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
#[Internal(reason: 'Implemented by DatabaseOutboxPort; relay-only contract, not part of the public API')]
interface OutboxPort
{
    /**
     * Store an event envelope in the outbox for later publishing.
     */
    public function store(EventEnvelope $envelope): void;

    /**
     * Store a batch of event envelopes in the outbox for later publishing.
     *
     * @param list<EventEnvelope> $envelopes
     */
    public function storeBatch(array $envelopes): void;

    /**
     * Mark an outbox entry as published.
     */
    public function markPublished(string $eventId): void;

    /**
     * Record a publish failure: increment the attempt counter and store the
     * last error so the relay can bound retries and an operator can triage
     * without trawling logs.
     */
    public function recordFailure(string $eventId, string $error): void;

    /**
     * Retrieve pending (unpublished, not dead-lettered) events, oldest first.
     *
     * @return list<EventEnvelope>
     */
    public function pendingEvents(int $limit = 100): array;

    /**
     * Retrieve events that exhausted their publish-attempt budget and were
     * dead-lettered. Surfaced (never auto-deleted) so auditors and operators
     * can inspect permanently-failing integration events.
     *
     * @return list<EventEnvelope>
     */
    public function deadLetteredEvents(int $limit = 100): array;
}
