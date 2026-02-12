<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;

/**
 * Module-scoped event dispatcher that stamps originModule on envelopes.
 *
 * Wraps an inner EventDispatcher and ensures all envelopes dispatched
 * through this module carry the correct origin module identifier.
 */
#[Internal]
final readonly class ModuleEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private EventDispatcherInterface $inner,
        private string $moduleId,
    ) {}

    #[Override]
    public function dispatch(object $event): object
    {
        if ($event instanceof EventEnvelope) {
            $event = $this->stampOriginModule($event);

            return $this->inner->dispatch($event);
        }

        return $this->inner->dispatch($event);
    }

    #[Override]
    public function dispatchEnvelope(EventEnvelope $envelope): EventEnvelope
    {
        $envelope = $this->stampOriginModule($envelope);

        return $this->inner->dispatchEnvelope($envelope);
    }

    private function stampOriginModule(EventEnvelope $envelope): EventEnvelope
    {
        return new EventEnvelope(
            eventId: $envelope->eventId,
            eventType: $envelope->eventType,
            schemaVersion: $envelope->schemaVersion,
            metadata: $envelope->metadata,
            payload: $envelope->payload,
            payloadHash: $envelope->payloadHash,
            originModule: $this->moduleId,
            scope: $envelope->scope,
        );
    }
}
