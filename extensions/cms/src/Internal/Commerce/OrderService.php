<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\OrderStatusStateMachine;
use Pulsar\Extension\Cms\Commerce\PaymentGateway;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function sprintf;

/**
 * Order lifecycle management: payment confirmation, failure, refunds, and fulfillment.
 */
#[Internal(reason: 'Order lifecycle service — not part of public API')]
final readonly class OrderService
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private InvoiceServiceInterface $invoiceService,
        private DigitalDeliveryServiceInterface $digitalDelivery,
        private ConnectionInterface $db,
        private EventDispatcherInterface $events,
        private ?PaymentGateway $paymentGateway = null,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Confirm payment for an order (idempotent — skips if already Confirmed).
     */
    public function confirmPayment(string $orderId, string $paymentIntentId): void
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw CmsException::contentNotFound($orderId);
        }

        // Idempotent: skip if already confirmed
        if ($order->status === OrderStatus::Confirmed) {
            return;
        }

        OrderStatusStateMachine::transition($order->status, OrderStatus::Confirmed);

        $this->db->execute(
            <<<'SQL'
                UPDATE cms_orders
                SET status = :status, payment_status = :payment_status,
                    payment_intent_id = :payment_intent_id, updated_at = :now
                WHERE id = :id
                SQL,
            [
                'status' => OrderStatus::Confirmed->value,
                'payment_status' => PaymentStatus::Paid->value,
                'payment_intent_id' => $paymentIntentId,
                'now' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'id' => $orderId,
            ],
        );

        $this->invoiceService->generate($orderId);
        $this->digitalDelivery->createDownloadTokens($orderId);

        $this->events->dispatch(new PaymentReceivedEvent($order, $paymentIntentId));
        $this->events->dispatch(new OrderStatusChangedEvent($order, OrderStatus::Confirmed));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.commerce.payment.confirmed',
            "order:{$orderId}",
            ['paymentIntentId' => $paymentIntentId],
        );
    }

    /**
     * Record a failed payment attempt.
     */
    public function failPayment(string $orderId, string $reason): void
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw CmsException::contentNotFound($orderId);
        }

        OrderStatusStateMachine::transition($order->status, OrderStatus::Failed);

        $this->db->execute(
            <<<'SQL'
                UPDATE cms_orders
                SET status = :status, payment_status = :payment_status, updated_at = :now
                WHERE id = :id
                SQL,
            [
                'status' => OrderStatus::Failed->value,
                'payment_status' => PaymentStatus::Failed->value,
                'now' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'id' => $orderId,
            ],
        );

        $this->events->dispatch(new OrderStatusChangedEvent($order, OrderStatus::Failed));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Failure,
            null,
            'cms.commerce.payment.failed',
            "order:{$orderId}",
            ['reason' => $reason],
        );
    }

    /**
     * Process a full or partial refund.
     *
     * Uses an atomic UPDATE with a ceiling check to prevent over-refunding,
     * even under concurrent requests.
     */
    public function refund(string $orderId, int $amount, string $reason, string $actorId): void
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw CmsException::contentNotFound($orderId);
        }

        if ($amount <= 0) {
            throw new CmsException('Refund amount must be positive');
        }

        // Atomically increment amount_refunded, enforcing the ceiling
        $affected = $this->db->execute(
            <<<'SQL'
                UPDATE cms_orders
                SET amount_refunded = amount_refunded + :amount, updated_at = :now
                WHERE id = :id AND (amount_refunded + :amount) <= total
                SQL,
            [
                'amount' => $amount,
                'now' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'id' => $orderId,
            ],
        );

        if ($affected === 0) {
            throw new CmsException(sprintf(
                'Refund amount %d would exceed refundable balance for order %s',
                $amount,
                $orderId,
            ));
        }

        // Process refund through payment gateway
        if ($this->paymentGateway !== null && $order->paymentIntentId !== null) {
            $this->paymentGateway->refund($order->paymentIntentId, $amount, $reason);
        }

        $newRefundedTotal = $order->amountRefunded + $amount;
        $isFullRefund = $newRefundedTotal >= $order->total;
        $newPaymentStatus = $isFullRefund ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;

        if ($isFullRefund) {
            OrderStatusStateMachine::transition($order->status, OrderStatus::Refunded);
        }

        $this->db->execute(
            <<<'SQL'
                UPDATE cms_orders
                SET status = :status, payment_status = :payment_status, updated_at = :now
                WHERE id = :id
                SQL,
            [
                'status' => $isFullRefund ? OrderStatus::Refunded->value : $order->status->value,
                'payment_status' => $newPaymentStatus->value,
                'now' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'id' => $orderId,
            ],
        );

        $this->events->dispatch(new RefundProcessedEvent($order, $amount, $reason));

        if ($isFullRefund) {
            $this->events->dispatch(new OrderStatusChangedEvent($order, OrderStatus::Refunded));
        }

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $actorId,
            'cms.commerce.refund.processed',
            "order:{$orderId}",
            ['amount' => $amount, 'reason' => $reason, 'full' => $isFullRefund],
        );
    }

    /**
     * Mark an order as fulfilled.
     */
    public function fulfill(string $orderId, string $actorId): void
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw CmsException::contentNotFound($orderId);
        }

        OrderStatusStateMachine::transition($order->status, OrderStatus::Fulfilled);
        $this->orders->updateStatus($orderId, OrderStatus::Fulfilled);

        $this->events->dispatch(new OrderStatusChangedEvent($order, OrderStatus::Fulfilled));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $actorId,
            'cms.commerce.order.fulfilled',
            "order:{$orderId}",
            ['orderNumber' => $order->orderNumber],
        );
    }
}
