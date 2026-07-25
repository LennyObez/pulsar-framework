<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal\Outbox;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Saga\Port\IntegrationEventBusPort;

/**
 * In-process {@see IntegrationEventBusPort} that delivers relayed outbox events
 * to their (cross-module) listeners through the framework's event dispatcher.
 *
 * This is the default bus for the modular monolith: "other services" are other
 * modules whose listeners are keyed by event type. The {@see OutboxRelay} drains
 * committed events and calls {@see publish()}; this wraps the type + payload in a
 * fresh {@see EventEnvelope} (a new correlation id — the port contract carries
 * only type and payload) and dispatches it. A deployment that federates across
 * process boundaries binds its own broker-backed IntegrationEventBusPort
 * instead.
 */
#[Internal(reason: 'Default in-process integration event bus for the outbox relay')]
final readonly class DispatchingIntegrationEventBus implements IntegrationEventBusPort
{
    /** Schema version stamped on re-dispatched integration envelopes. */
    private const int SCHEMA_VERSION = 1;

    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {}

    #[Override]
    public function publish(string $eventType, array $payload): void
    {
        $envelope = EventEnvelope::wrap(
            $eventType,
            self::SCHEMA_VERSION,
            $payload,
            new EventMetadata(
                correlationId: CorrelationId::generate(),
                causationId: CausationId::generate(),
            ),
        );

        $this->dispatcher->dispatchEnvelope($envelope);
    }
}
