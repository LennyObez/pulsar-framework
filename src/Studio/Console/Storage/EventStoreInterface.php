<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Storage;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\EventEnvelope;

/**
 * Contract for Studio event storage.
 */
#[Internal]
interface EventStoreInterface
{
    /**
     * Store an event with its payload JSON and optional tenant hash.
     */
    public function store(EventEnvelope $envelope, string $payloadJson, ?string $tenantHash = null): void;

    /**
     * Query events matching the given criteria.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function query(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * Count events matching the given criteria.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int;

    /**
     * Get a single event by its event ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $eventId): ?array;

    /**
     * Get the total storage size in bytes.
     */
    public function sizeInBytes(): int;

    /**
     * Delete events older than the given timestamp (microseconds).
     */
    public function deleteOlderThan(int $timestampUs): int;

    /**
     * Delete all events.
     */
    public function clear(): void;

    /**
     * Run VACUUM to reclaim space.
     */
    public function vacuum(): void;
}
