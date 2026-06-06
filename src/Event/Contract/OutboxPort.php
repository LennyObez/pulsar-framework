<?php

declare(strict_types=1);

namespace Pulsar\Event\Contract;

use Pulsar\Api\Internal;
use Pulsar\Event\EventEnvelope;

/**
 * Port for transactional outbox pattern.
 *
 * Implementations store events alongside domain writes in the same transaction,
 * then publish asynchronously via a relay process.
 */
#[Internal(reason: 'Unimplemented port; will be promoted to #[Api] when an adapter ships')]
interface OutboxPort
{
    /**
     * Store an event envelope in the outbox for later publishing.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function store(EventEnvelope $envelope): void;

    /**
     * Store a batch of event envelopes in the outbox for later publishing.
     *
     * @param list<EventEnvelope> $envelopes
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function storeBatch(array $envelopes): void;

    /**
     * Mark an outbox entry as published.
     */
    public function markPublished(string $eventId): void;

    /**
     * Retrieve pending (unpublished) events.
     *
     * @return list<EventEnvelope>
     */
    public function pendingEvents(int $limit = 100): array;
}
