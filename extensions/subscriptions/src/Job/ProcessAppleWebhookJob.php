<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Job;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueableInterface;
use RuntimeException;
use Throwable;

/**
 * Queued job for processing an Apple App Store Server Notification v2.
 *
 * Defers the actual subscription status update to the SubscriptionService,
 * ensuring that webhook acknowledgement and processing are decoupled.
 */
#[Internal(reason: 'Queue job — implementation detail')]
final readonly class ProcessAppleWebhookJob implements QueueableInterface
{
    public function __construct(
        private string $notificationType,
        private string $originalTransactionId,
        private SubscriptionStatus $newStatus,
        private string $encryptedPayload,
        private SubscriptionService $subscriptionService,
    ) {}

    #[Override]
    public function handle(JobContext $context): void
    {
        try {
            $this->subscriptionService->processWebhook(
                store: Store::Apple,
                eventType: $this->notificationType,
                originalTransactionId: $this->originalTransactionId,
                newStatus: $this->newStatus,
                encryptedPayload: $this->encryptedPayload,
                signatureVerified: true,
            );
        } catch (Throwable) {
            throw new RuntimeException(
                "Failed to process Apple webhook: $this->notificationType",
            );
        }
    }

    #[Override]
    public function queue(): string
    {
        return 'subscriptions';
    }

    #[Override]
    public function maxAttempts(): int
    {
        return 5;
    }

    #[Override]
    public function timeout(): int
    {
        return 30;
    }
}
