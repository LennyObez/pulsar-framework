<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal;

use Override;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventScope;
use Pulsar\Event\Exception\EventException;
use Pulsar\Event\ListenerProviderInterface;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Throwable;

/**
 * Core PSR-14 event dispatcher with storm protection, scope computation, and metrics.
 */
#[Internal]
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private ListenerProviderInterface $listenerProvider,
        private ?ListenerMetadataProviderInterface $metadataProvider,
        private StormGuard $stormGuard,
        private ?MetricRegistry $metrics = null,
        private ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    public function dispatch(object $event): object
    {
        $eventClass = $event::class;

        $eventType = $event instanceof EventEnvelope ? $event->eventType : $eventClass;

        // Envelope-required check: must throw even with zero listeners
        $this->enforceEnvelopeRequirement($event, $eventClass);

        // Compute scope inline for envelopes
        if ($event instanceof EventEnvelope && $this->metadataProvider !== null && $event->originModule !== null) {
            $scope = $this->computeScope($event);
            $event = new EventEnvelope(
                eventId: $event->eventId,
                eventType: $event->eventType,
                schemaVersion: $event->schemaVersion,
                metadata: $event->metadata,
                payload: $event->payload,
                payloadHash: $event->payloadHash,
                originModule: $event->originModule,
                scope: $scope,
            );
        }

        // Resolve storm override
        $overrideMaxDepth = $this->resolveStormOverride($eventClass);

        $this->logger?->debug('Dispatching event', ['event_type' => $eventType]);

        // Storm protection: enter
        try {
            $this->stormGuard->enter($eventType, $overrideMaxDepth);
        } catch (EventException $e) {
            $this->metrics?->counter('pulsar_event_storm_prevented_total', 'Event storms prevented')
                ->increment(new LabelSet(['event_class' => $eventType]));

            throw $e;
        }

        // Emit depth gauge
        $this->metrics?->gauge('pulsar_event_dispatch_depth', 'Current event dispatch depth')
            ->set((float) $this->stormGuard->currentDepth(), new LabelSet(['event_class' => $eventType]));

        try {
            $listeners = $this->listenerProvider->getListenersForEvent($event);
            /** @var list<Throwable> $listenerErrors */
            $listenerErrors = [];

            /** @var mixed $listener */
            foreach ($listeners as $listener) {
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    break;
                }

                try {
                    /** @var callable $listener */
                    $listener($event);
                } catch (Throwable $e) {
                    $listenerErrors[] = $e;

                    $this->logger?->error('Event listener threw exception', [
                        'event_type' => $eventType,
                        'listener' => get_debug_type($listener),
                        'error' => $e->getMessage(),
                    ]);

                    $this->metrics?->counter('pulsar_event_listener_error_total', 'Event listener errors')
                        ->increment(new LabelSet(['event_class' => $eventType]));
                }
            }

            // Emit dispatch counter
            $this->emitDispatchMetric($event, $eventType);

            if ($listenerErrors !== []) {
                throw $listenerErrors[0];
            }

            return $event;
        } finally {
            $this->stormGuard->leave();
        }
    }

    #[Override]
    public function dispatchEnvelope(EventEnvelope $envelope): EventEnvelope
    {
        /** @var EventEnvelope */
        return $this->dispatch($envelope);
    }

    /**
     * Enforce envelope requirement: if event class requires envelope dispatch
     * but was dispatched as a plain object (not an EventEnvelope), throw.
     *
     * @param class-string $eventClass
     */
    private function enforceEnvelopeRequirement(object $event, string $eventClass): void
    {
        if ($event instanceof EventEnvelope) {
            return;
        }

        $requiresEnvelope = $this->metadataProvider?->requiresEnvelopeFor($eventClass) ?? false;

        if ($requiresEnvelope) {
            throw EventException::envelopeRequired($eventClass);
        }
    }

    /**
     * Resolve storm override for the given event class.
     *
     * Prefers metadata provider (pre-resolved at compile time);
     * falls back to reflection-with-static-cache for dynamic providers.
     *
     * @param class-string $eventClass
     */
    private function resolveStormOverride(string $eventClass): ?int
    {
        return $this->metadataProvider?->stormOverrideFor($eventClass);
    }

    /**
     * Compute event scope by comparing origin module against listener modules.
     */
    private function computeScope(EventEnvelope $envelope): EventScope
    {
        if ($this->metadataProvider === null || $envelope->originModule === null) {
            return EventScope::CrossModule;
        }

        $listenerModuleIds = $this->metadataProvider->listenerModuleIdsFor($envelope::class);

        if ($listenerModuleIds === []) {
            return EventScope::Internal;
        }

        return array_any($listenerModuleIds, static fn(string $moduleId): bool => $moduleId !== $envelope->originModule)
            ? EventScope::CrossModule
            : EventScope::Internal;
    }

    /**
     * Emit the dispatch metric with event type, module, and scope labels.
     */
    private function emitDispatchMetric(object $event, string $eventType): void
    {
        if ($this->metrics === null) {
            return;
        }

        $module = '';
        $scope = EventScope::CrossModule->value;

        if ($event instanceof EventEnvelope) {
            $module = $event->originModule ?? '';
            $scope = $event->scope->value;
        }

        $this->metrics->counter('pulsar_event_dispatched_total', 'Events dispatched')
            ->increment(new LabelSet(['event_class' => $eventType, 'module' => $module, 'scope' => $scope]));
    }
}
