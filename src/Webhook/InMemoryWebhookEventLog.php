<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Webhook\Exception\WebhookException;

/**
 * In-memory webhook replay prevention store with Fiber-safe mutex.
 */
#[Api(since: '1.0.0')]
final class InMemoryWebhookEventLog implements WebhookEventLogInterface
{
    /** @var array<string, DateTimeImmutable> eventId => processedAt */
    private array $processed = [];

    /** @var array<string, DateTimeImmutable> eventId => expiresAt */
    private array $expiry = [];

    /** @var array<string, bool> Fiber-safe in-flight mutex */
    private array $inFlight = [];

    #[Override]
    public function claim(string $eventId, DateTimeImmutable $now, int $ttlSeconds): WebhookClaim
    {
        // Check for concurrent in-flight claim
        if (isset($this->inFlight[$eventId])) {
            throw WebhookException::concurrentClaim($eventId);
        }

        // Check for already-processed event
        if (isset($this->processed[$eventId])) {
            // Check if expired
            if (isset($this->expiry[$eventId]) && $this->expiry[$eventId] <= $now) {
                unset($this->processed[$eventId], $this->expiry[$eventId]);
            } else {
                return WebhookClaim::replay($this->processed[$eventId]);
            }
        }

        // Claim the event
        $this->inFlight[$eventId] = true;

        return WebhookClaim::claimed();
    }

    #[Override]
    public function commit(string $eventId): void
    {
        $now = new DateTimeImmutable();
        $this->processed[$eventId] = $now;

        if (!isset($this->expiry[$eventId])) {
            // Default 72h expiry
            $this->expiry[$eventId] = $now->modify('+259200 seconds');
        }

        unset($this->inFlight[$eventId]);
    }

    #[Override]
    public function release(string $eventId): void
    {
        unset($this->inFlight[$eventId]);
    }

    #[Override]
    public function prune(DateTimeImmutable $before): int
    {
        $pruned = 0;

        foreach ($this->expiry as $eventId => $expiresAt) {
            if ($expiresAt <= $before) {
                unset($this->processed[$eventId], $this->expiry[$eventId]);
                $pruned++;
            }
        }

        return $pruned;
    }
}
