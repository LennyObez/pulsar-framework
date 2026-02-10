<?php

declare(strict_types=1);

namespace Pulsar\Saga\Port;

use Pulsar\Api\Api;

/**
 * Port for durable event emission via the transactional outbox pattern.
 *
 * State changes and outbox event writes happen in the same database
 * transaction. An outbox relay publishes events asynchronously after commit.
 *
 * In regulated presets, this is the ONLY allowed mechanism for emitting
 * integration events from saga step handlers. Direct use of
 * {@see IntegrationEventBusPort} is forbidden.
 */
#[Api(since: '1.0.0')]
interface OutboxPort
{
    /**
     * Write an event to the transactional outbox.
     *
     * The event will be published asynchronously by the outbox relay
     * after the enclosing transaction commits.
     *
     * @param string               $eventType Event type identifier
     * @param array<string, mixed> $payload   Event payload
     * @param string|null          $aggregateId Optional aggregate ID for ordering
     */
    public function write(string $eventType, array $payload, ?string $aggregateId = null): void;
}
