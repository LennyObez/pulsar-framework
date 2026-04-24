<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Webhook;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;

use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Handles Stripe webhook events.
 *
 * Processes subscription lifecycle events (created, updated, deleted)
 * and payment events (succeeded, failed).
 */
#[Internal]
final readonly class StripeWebhookHandler
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private StripeConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Verify and process a Stripe webhook event.
     *
     * @return array{verified: bool, event_type: string, processed: bool}
     */
    public function handle(string $payload, string $signatureHeader): array
    {
        if (!$this->verifySignature($payload, $signatureHeader)) {
            $this->logger->warning('Stripe webhook signature verification failed');

            return ['verified' => false, 'event_type' => '', 'processed' => false];
        }

        /** @var array<string, mixed> $event */
        $event = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);

        /** @var string $eventType */
        $eventType = is_string($event['type'] ?? null) ? $event['type'] : '';

        /** @var array<string, mixed> $eventData */
        $eventData = is_array($event['data'] ?? null) ? $event['data'] : [];
        /** @var array<string, mixed> $object */
        $object = is_array($eventData['object'] ?? null) ? $eventData['object'] : [];

        $processed = $this->processEvent($eventType, $object);

        return ['verified' => true, 'event_type' => $eventType, 'processed' => $processed];
    }

    /**
     * @param array<string, mixed> $object
     */
    private function processEvent(string $eventType, array $object): bool
    {
        return match ($eventType) {
            'customer.subscription.updated' => $this->handleSubscriptionUpdate($object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($object),
            'invoice.payment_failed' => $this->handlePaymentFailed($object),
            'invoice.payment_succeeded' => $this->handlePaymentSucceeded($object),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handleSubscriptionUpdate(array $object): bool
    {
        /** @var mixed $rawGatewayId */
        $rawGatewayId = $object['id'] ?? null;
        /** @var mixed $rawStatus */
        $rawStatus = $object['status'] ?? null;
        $gatewayId = is_string($rawGatewayId) ? $rawGatewayId : '';
        $status = is_string($rawStatus) ? $rawStatus : '';

        if ($gatewayId === '') {
            return false;
        }

        $subscription = $this->subscriptionRepository->findByGatewayId($gatewayId);

        if ($subscription === null) {
            $this->logger->warning('Stripe webhook: subscription not found', ['gateway_id' => $gatewayId]);

            return false;
        }

        $newStatus = match ($status) {
            'active' => SubscriptionStatus::Active,
            'past_due' => SubscriptionStatus::PastDue,
            'canceled' => SubscriptionStatus::Cancelled,
            'unpaid' => SubscriptionStatus::Expired,
            'trialing' => SubscriptionStatus::Trialing,
            'paused' => SubscriptionStatus::Paused,
            default => null,
        };

        if ($newStatus === null) {
            return false;
        }

        $updated = $subscription->withStatus($newStatus);
        $this->subscriptionRepository->save($updated);

        $this->logger->info('Stripe subscription updated', [
            'subscription_id' => $subscription->id,
            'new_status' => $newStatus->value,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handleSubscriptionDeleted(array $object): bool
    {
        /** @var mixed $rawGatewayId */
        $rawGatewayId = $object['id'] ?? null;
        $gatewayId = is_string($rawGatewayId) ? $rawGatewayId : '';

        if ($gatewayId === '') {
            return false;
        }

        $subscription = $this->subscriptionRepository->findByGatewayId($gatewayId);

        if ($subscription === null) {
            return false;
        }

        $cancelled = $subscription->withStatus(SubscriptionStatus::Cancelled);
        $this->subscriptionRepository->save($cancelled);

        return true;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handlePaymentFailed(array $object): bool
    {
        /** @var mixed $rawSubscriptionId */
        $rawSubscriptionId = $object['subscription'] ?? null;
        $subscriptionId = is_string($rawSubscriptionId) ? $rawSubscriptionId : '';

        if ($subscriptionId === '') {
            return false;
        }

        $subscription = $this->subscriptionRepository->findByGatewayId($subscriptionId);

        if ($subscription === null) {
            return false;
        }

        $pastDue = $subscription->withStatus(SubscriptionStatus::PastDue);
        $this->subscriptionRepository->save($pastDue);

        $this->logger->warning('Payment failed for subscription', [
            'subscription_id' => $subscription->id,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handlePaymentSucceeded(array $object): bool
    {
        /** @var mixed $rawSubscriptionId */
        $rawSubscriptionId = $object['subscription'] ?? null;
        $subscriptionId = is_string($rawSubscriptionId) ? $rawSubscriptionId : '';

        if ($subscriptionId === '') {
            return false;
        }

        $subscription = $this->subscriptionRepository->findByGatewayId($subscriptionId);

        if ($subscription === null) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::PastDue) {
            $active = $subscription->withStatus(SubscriptionStatus::Active);
            $this->subscriptionRepository->save($active);
        }

        return true;
    }

    /**
     * Maximum allowed age of a webhook timestamp in seconds (5 minutes).
     *
     * Webhooks older than this are rejected to prevent replay attacks.
     */
    private const int TIMESTAMP_TOLERANCE_SECONDS = 300;

    /**
     * Verify Stripe webhook signature with replay protection.
     *
     * Parses the Stripe-Signature header (t=timestamp,v1=signature),
     * verifies the HMAC-SHA256 signature, and rejects timestamps
     * outside the tolerance window to prevent replay attacks.
     *
     * @param int|null $currentTime Current Unix timestamp (injectable for testing)
     */
    private function verifySignature(string $payload, string $signatureHeader, ?int $currentTime = null): bool
    {
        if ($this->config->webhookSecret === '') {
            return false;
        }

        // Parse Stripe signature: t=timestamp,v1=signature
        $parts = explode(',', $signatureHeader);
        $timestamp = '';
        $signatures = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $signatures[] = substr($part, 3);
            }
        }

        if ($timestamp === '' || $signatures === []) {
            return false;
        }

        // Replay protection: reject timestamps outside the tolerance window
        $timestampInt = (int) $timestamp;
        $now = $currentTime ?? time();

        if (abs($now - $timestampInt) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            $this->logger->warning('Stripe webhook rejected: timestamp outside tolerance window', [
                'timestamp' => $timestampInt,
                'current_time' => $now,
                'drift_seconds' => abs($now - $timestampInt),
            ]);

            return false;
        }

        $expectedSignature = hash_hmac('sha256', "$timestamp.$payload", $this->config->webhookSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }
}
