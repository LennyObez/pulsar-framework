<?php

declare(strict_types=1);

namespace Pulsar\Event\Contract;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Event\EventEnvelope;

/**
 * Port for event store implementations (event sourcing).
 *
 * Stores the full history of events for an aggregate, enabling
 * event replay and temporal queries.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Unimplemented port; will be promoted to #[Api] when an adapter ships')]
interface EventStorePort
{
    /**
     * Append an event to the store for a given aggregate.
     */
    public function append(string $aggregateId, EventEnvelope $envelope): void;

    /**
     * Retrieve all events for a given aggregate, ordered by occurrence.
     *
     * @return list<EventEnvelope>
     */
    public function eventsFor(string $aggregateId): array;

    /**
     * Purge events for a given aggregate, optionally before a cutoff date.
     *
     * @return int Number of events purged
     */
    public function purge(string $aggregateId, ?DateTimeImmutable $before = null): int;
}
