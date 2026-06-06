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
 * Queued job for processing a Google Play RTDN webhook notification.
 *
 * Defers the actual subscription status update to the SubscriptionService,
 * ensuring that webhook acknowledgement and processing are decoupled.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Queue job; implementation detail')]
final readonly class ProcessGoogleWebhookJob implements QueueableInterface
{
    public function __construct(
        private string $eventType,
        private string $purchaseToken,
        private SubscriptionStatus $newStatus,
        private string $encryptedPayload,
        private SubscriptionService $subscriptionService,
    ) {}

    #[Override]
    public function handle(JobContext $context): void
    {
        try {
            $this->subscriptionService->processWebhook(
                store: Store::Google,
                eventType: $this->eventType,
                originalTransactionId: $this->purchaseToken,
                newStatus: $this->newStatus,
                encryptedPayload: $this->encryptedPayload,
                signatureVerified: true,
            );
        } catch (Throwable $e) {
            // Retries are handled by the queue infrastructure. Preserve the
            // original exception chain so production logs retain the root
            // cause (Google API error, signature mismatch, store error, etc.)
            // instead of a generic "Failed to process" message.
            throw new RuntimeException(
                "Failed to process Google webhook: $this->eventType",
                previous: $e,
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
