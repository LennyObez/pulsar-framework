<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Webhook\Exception\WebhookException;

/**
 * In-memory webhook replay prevention store with Fiber-safe mutex.
 * @api
 */
#[Api(since: '1.0.0')]
final class InMemoryWebhookEventLog implements WebhookEventLogInterface
{
    /** @var array<string, DateTimeImmutable> eventId => processedAt */
    private array $processed = [];

    /** @var array<string, DateTimeImmutable> eventId => expiresAt */
    private array $expiry = [];

    /** @var array<string, array{now: DateTimeImmutable, ttl: int}> claim-time data captured for use by commit() */
    private array $claimContext = [];

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
                unset($this->processed[$eventId], $this->expiry[$eventId], $this->claimContext[$eventId]);
            } else {
                return WebhookClaim::replay($this->processed[$eventId]);
            }
        }

        // Claim the event and remember the caller-supplied clock + TTL so commit()
        // can derive expiry deterministically (no hidden wall-clock dependency).
        $this->inFlight[$eventId] = true;
        $this->claimContext[$eventId] = ['now' => $now, 'ttl' => $ttlSeconds];

        return WebhookClaim::claimed();
    }

    #[Override]
    public function commit(string $eventId): void
    {
        $context = $this->claimContext[$eventId] ?? null;
        $now = $context['now'] ?? new DateTimeImmutable();
        $ttlSeconds = $context['ttl'] ?? 259200;

        $this->processed[$eventId] = $now;

        if (!isset($this->expiry[$eventId])) {
            $this->expiry[$eventId] = $now->modify('+' . $ttlSeconds . ' seconds');
        }

        unset($this->inFlight[$eventId], $this->claimContext[$eventId]);
    }

    #[Override]
    public function release(string $eventId): void
    {
        unset($this->inFlight[$eventId], $this->claimContext[$eventId]);
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
