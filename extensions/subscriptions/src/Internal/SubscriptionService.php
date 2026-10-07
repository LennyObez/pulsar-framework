<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Internal;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\WebhookEvent;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;
use RuntimeException;

use function hash;

/**
 * Core subscription orchestrator.
 *
 * Coordinates between the store verifiers, the subscription repository,
 * and webhook event storage. All public methods are idempotent and safe
 * for concurrent access: duplicate purchase tokens resolve to the same
 * subscription via the purchase_token_hash unique constraint.
 */
#[Internal(reason: 'Orchestration service; wire via SubscriptionsServiceProvider')]
final readonly class SubscriptionService
{
    public function __construct(
        private SubscriptionVerifierInterface $verifier,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private WebhookEventRepositoryInterface $webhookRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Verify a purchase token and create or update the subscription.
     *
     * @param string $userId The authenticated user making the request
     * @param Store $store Which store issued the token
     * @param string $purchaseToken The raw token from the client
     * @param string $plan Human-readable plan name
     *
     * @throws RuntimeException If verification fails
     */
    public function verifyAndSave(
        string $userId,
        Store $store,
        string $purchaseToken,
        string $plan,
    ): Subscription {
        $result = $this->verifier->verify($store, $purchaseToken);

        if (!$result->isValid) {
            $this->logger->warning('Subscription verification failed', [
                'user_id' => $userId,
                'store' => $store->value,
            ]);

            throw new RuntimeException('Purchase verification failed');
        }

        $tokenHash = self::hashToken($purchaseToken);

        // Check for existing subscription by token hash (idempotency)
        $existing = $this->subscriptionRepository->findByPurchaseTokenHash($tokenHash);

        if ($existing !== null) {
            $updated = $existing->withStatus(
                status: SubscriptionStatus::Active,
                expiresAt: $result->expiresAt,
                gracePeriodUntil: $result->gracePeriodUntil,
            );

            $this->subscriptionRepository->save($updated);

            $this->logger->info('Subscription re-verified', [
                'subscription_id' => $updated->id,
                'user_id' => $userId,
                'store' => $store->value,
            ]);

            return $updated;
        }

        $subscription = Subscription::create(
            userId: $userId,
            store: $store,
            productId: $result->productId,
            plan: $plan,
            purchaseTokenHash: $tokenHash,
            originalTransactionId: $purchaseToken,
            expiresAt: $result->expiresAt,
        );

        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Subscription created', [
            'subscription_id' => $subscription->id,
            'user_id' => $userId,
            'store' => $store->value,
            'product_id' => $result->productId,
        ]);

        return $subscription;
    }

    /**
     * Fetch the current subscription status for a user.
     */
    public function getStatus(string $userId): ?Subscription
    {
        return $this->subscriptionRepository->findByUser($userId);
    }

    /**
     * Restore a subscription from a previous purchase (e.g. after app reinstall).
     *
     * Re-verifies the token with the store and updates the local record.
     */
    public function restore(
        string $userId,
        Store $store,
        string $purchaseToken,
        string $plan,
    ): Subscription {
        return $this->verifyAndSave($userId, $store, $purchaseToken, $plan);
    }

    /**
     * Process an inbound webhook notification from a store.
     *
     * Records the event, then applies the status change to the matching subscription.
     *
     * @param Store $store Source platform
     * @param string $eventType Store-specific notification type
     * @param string $originalTransactionId Transaction ID to match
     * @param SubscriptionStatus $newStatus Resolved subscription status
     * @param string $encryptedPayload Encrypted raw payload for audit
     * @param bool $signatureVerified Whether the webhook signature was validated
     */
    public function processWebhook(
        Store $store,
        string $eventType,
        string $originalTransactionId,
        SubscriptionStatus $newStatus,
        string $encryptedPayload,
        bool $signatureVerified,
    ): void {
        $event = WebhookEvent::create(
            store: $store,
            eventType: $eventType,
            payloadEncrypted: $encryptedPayload,
            signatureVerified: $signatureVerified,
        );

        $this->webhookRepository->save($event);

        $subscription = $this->subscriptionRepository->findByOriginalTransactionId($originalTransactionId);

        if ($subscription === null) {
            $this->logger->warning('Webhook for unknown subscription', [
                'store' => $store->value,
                'event_type' => $eventType,
                'original_transaction_id' => $originalTransactionId,
            ]);

            return;
        }

        $updated = $subscription->withStatus($newStatus);
        $this->subscriptionRepository->save($updated);

        $processed = $event->markProcessed();
        $this->webhookRepository->save($processed);

        $this->logger->info('Webhook processed', [
            'event_id' => $event->id,
            'subscription_id' => $subscription->id,
            'event_type' => $eventType,
            'new_status' => $newStatus->value,
        ]);
    }

    /**
     * Hash a purchase token for storage (one-way, using SHA-256).
     */
    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
