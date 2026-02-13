<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentGateway;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function is_string;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Handles incoming payment provider webhooks for asynchronous payment events.
 */
#[Internal(reason: 'Internal webhook processing — not part of public API')]
final readonly class WebhookHandler
{
    /** Maximum age (in seconds) for a webhook timestamp to be considered valid. */
    private const int MAX_WEBHOOK_AGE_SECONDS = 300;

    private const int DEFAULT_MAX_RETRIES = 5;

    public function __construct(
        private OrderService $orderService,
        private OrderRepositoryInterface $orders,
        private PaymentGateway $paymentGateway,
        private ConnectionInterface $connection,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?QueueDriverInterface $queueDriver = null,
    ) {}

    /**
     * Process a webhook payload from the payment provider.
     *
     * @param string $payload Raw request body
     * @param string $signature Signature header for verification
     * @throws CmsException If the signature is invalid
     * @throws JsonException If the payload is not valid JSON
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

        /** @var array{id?: string, created?: int, type: string, data: array{object: array{id?: string, metadata?: array{orderId?: string}, failure_message?: string, charge?: array{refunded?: bool, amount_refunded?: int, metadata?: array{orderId?: string}}}}} $event */
        $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        // Replay protection: reject webhooks with stale timestamps
        $eventTimestamp = isset($event['created']) ? (int) $event['created'] : 0;

        if ($eventTimestamp > 0 && (time() - $eventTimestamp) > self::MAX_WEBHOOK_AGE_SECONDS) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                null,
                'cms.commerce.webhook.timestamp_expired',
                'webhook:payment',
                ['event_age_seconds' => time() - $eventTimestamp],
            );

            throw new CmsException('Webhook timestamp too old');
        }

        // Replay protection: reject duplicate event IDs
        $rawEventId = $event['id'] ?? null;
        $eventId = is_string($rawEventId) ? $rawEventId : '';

        if ($eventId !== '' && $this->isEventAlreadyProcessed($eventId)) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                null,
                'cms.commerce.webhook.duplicate_event',
                'webhook:payment',
                ['event_id' => $eventId],
            );

            return;
        }

        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        try {
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
        } catch (Throwable $e) {
            if ($this->queueDriver !== null) {
                $this->dispatchRetry($eventId, $payload, $signature);

                return;
            }

            throw $e;
        }

        // Record the event as processed to prevent replay
        if ($eventId !== '') {
            $this->recordProcessedEvent($eventId);
        }
    }

    private function isEventAlreadyProcessed(string $eventId): bool
    {
        $result = $this->connection->query(
            'SELECT 1 FROM cms_webhook_events WHERE event_id = :event_id',
            ['event_id' => $eventId],
        );

        return $result->rowCount > 0;
    }

    private function recordProcessedEvent(string $eventId): void
    {
        $this->connection->execute(
            'INSERT INTO cms_webhook_events (event_id) VALUES (:event_id)',
            ['event_id' => $eventId],
        );
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
            "order:$orderId",
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
            "order:$orderId",
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
            "order:$orderId",
            ['amount' => $refundAmount],
        );
    }

    private function dispatchRetry(string $eventId, string $payload, string $signature): void
    {
        /** @var QueueDriverInterface $queueDriver — non-null guaranteed by caller */
        $queueDriver = $this->queueDriver;

        $jobPayload = json_encode([
            'eventId' => $eventId,
            'payload' => $payload,
            'signature' => $signature,
            'retryCount' => 0,
            'maxRetries' => self::DEFAULT_MAX_RETRIES,
        ], JSON_THROW_ON_ERROR);

        $delaySeconds = WebhookRetryJob::calculateDelay(0);

        $queueDriver->push(
            'cms-webhooks',
            WebhookRetryJob::class,
            $jobPayload,
            $delaySeconds,
        );

        $this->auditLogger?->log(
            AuditEvent::SystemEvent,
            AuditOutcome::Success,
            null,
            'cms.commerce.webhook.retry_dispatched',
            "webhook:$eventId",
            ['retry_count' => 0, 'delay_seconds' => $delaySeconds],
        );
    }
}
