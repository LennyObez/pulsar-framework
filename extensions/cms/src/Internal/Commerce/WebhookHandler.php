<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentGateway;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Handles incoming payment provider webhooks for asynchronous payment events.
 */
#[Internal(reason: 'Internal webhook processing — not part of public API')]
final readonly class WebhookHandler
{
    public function __construct(
        private OrderService $orderService,
        private OrderRepositoryInterface $orders,
        private PaymentGateway $paymentGateway,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Process a webhook payload from the payment provider.
     *
     * @param string $payload Raw request body
     * @param string $signature Signature header for verification
     * @throws CmsException If the signature is invalid
     */
    public function handle(string $payload, string $signature): void
    {
        if (!$this->paymentGateway->verifyWebhookSignature($payload, $signature)) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                null,
                'cms.commerce.webhook.signature_invalid',
                'webhook:payment',
                [],
            );

            throw new CmsException('Invalid webhook signature');
        }

        /** @var array{type: string, data: array{object: array{id?: string, metadata?: array{orderId?: string}, failure_message?: string, charge?: array{refunded?: bool, amount_refunded?: int, metadata?: array{orderId?: string}}}}} $event */
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        match ($type) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($object),
            'charge.refunded' => $this->handleChargeRefunded($object),
            default => $this->auditLogger?->log(
                AuditEvent::SystemEvent,
                AuditOutcome::Success,
                null,
                'cms.commerce.webhook.unhandled',
                'webhook:payment',
                ['type' => $type],
            ),
        };
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handlePaymentSucceeded(array $object): void
    {
        $paymentIntentId = (string) ($object['id'] ?? '');
        $orderId = (string) ($object['metadata']['orderId'] ?? '');

        if ($orderId === '') {
            return;
        }

        $order = $this->orders->findById($orderId);

        if ($order === null) {
            return;
        }

        // Idempotent: skip if already confirmed
        if ($order->status === OrderStatus::Confirmed || $order->status === OrderStatus::Fulfilled) {
            return;
        }

        $this->orderService->confirmPayment($orderId, $paymentIntentId);

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.commerce.webhook.payment_succeeded',
            "order:{$orderId}",
            ['paymentIntentId' => $paymentIntentId],
        );
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handlePaymentFailed(array $object): void
    {
        $orderId = (string) ($object['metadata']['orderId'] ?? '');
        $reason = (string) ($object['failure_message'] ?? 'Payment failed');

        if ($orderId === '') {
            return;
        }

        $this->orderService->failPayment($orderId, $reason);

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Failure,
            null,
            'cms.commerce.webhook.payment_failed',
            "order:{$orderId}",
            ['reason' => $reason],
        );
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handleChargeRefunded(array $object): void
    {
        $orderId = (string) ($object['metadata']['orderId'] ?? '');
        $refundAmount = (int) ($object['amount_refunded'] ?? 0);

        if ($orderId === '' || $refundAmount === 0) {
            return;
        }

        $this->orderService->refund($orderId, $refundAmount, 'Refund via webhook', 'system');

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.commerce.webhook.charge_refunded',
            "order:{$orderId}",
            ['amount' => $refundAmount],
        );
    }
}
