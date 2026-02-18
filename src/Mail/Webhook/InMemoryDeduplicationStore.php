<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Internal;

use function array_filter;
use function sprintf;
use function time;

/**
 * In-memory webhook deduplication store for testing and single-process use.
 *
 * Keys are formatted as "{tenantId}:{eventId}" for tenant isolation.
 */
#[Internal]
final class InMemoryDeduplicationStore implements WebhookDeduplicationStoreInterface
{
    /** @var array<string, int> Event key => stored timestamp */
    private array $events = [];

    public function has(string $eventId, ?string $tenantId = null): bool
    {
        return isset($this->events[self::key($eventId, $tenantId)]);
    }

    public function store(string $eventId, ?string $tenantId = null): void
    {
        $this->events[self::key($eventId, $tenantId)] = time();
    }

    public function cleanup(int $maxAgeDays): void
    {
        $cutoff = time() - ($maxAgeDays * 86_400);

        $this->events = array_filter(
            $this->events,
            static fn(int $timestamp): bool => $timestamp >= $cutoff,
        );
    }

    private static function key(string $eventId, ?string $tenantId): string
    {
        if ($tenantId === null) {
            return $eventId;
        }

        return sprintf('%s:%s', $tenantId, $eventId);
    }
}
