<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;
use Pulsar\Config\StormProtectionConfig;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Event\EventScope;
use Pulsar\Event\Exception\EventException;
use Pulsar\Event\Internal\EventDispatcher;
use Pulsar\Event\Internal\ListenerMetadataProviderInterface;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Event\Internal\StormGuard;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use stdClass;

#[CoversClass(EventDispatcher::class)]
final class EventDispatcherTest extends TestCase
{
    private ListenerProvider $provider;
    private StormGuard $stormGuard;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        ListenerProvider::resetStaticCaches();
        $this->provider = new ListenerProvider();
        $this->stormGuard = new StormGuard(new StormProtectionConfig());
        $this->dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard);
    }

    #[Test]
    public function dispatchCallsListeners(): void
    {
        $called = false;
        $this->provider->addListener(stdClass::class, static function (stdClass $e) use (&$called): void {
            $called = true;
        });

        $this->dispatcher->dispatch(new stdClass());

        self::assertTrue($called);
    }

    #[Test]
    public function dispatchReturnsSameEvent(): void
    {
        $event = new stdClass();
        $returned = $this->dispatcher->dispatch($event);

        self::assertSame($event, $returned);
    }

    #[Test]
    public function dispatchStopsOnStoppableEvent(): void
    {
        $order = [];

        $this->provider->addListener(StoppableTestEvent::class, static function (StoppableTestEvent $e) use (&$order): void {
            $order[] = 'first';
            $e->stop();
        }, priority: 10);

        $this->provider->addListener(StoppableTestEvent::class, static function (StoppableTestEvent $e) use (&$order): void {
            $order[] = 'second';
        }, priority: 0);

        $event = new StoppableTestEvent();
        $this->dispatcher->dispatch($event);

        self::assertSame(['first'], $order);
    }

    #[Test]
    public function dispatchWithMetrics(): void
    {
        $metrics = new MetricRegistry();
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, $metrics);

        $dispatcher->dispatch(new stdClass());

        self::assertTrue($metrics->has('pulsar_event_dispatched_total'));
        self::assertTrue($metrics->has('pulsar_event_dispatch_depth'));
    }

    #[Test]
    public function dispatchThrowsOnStorm(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 1));
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $guard);

        $guard->enter(stdClass::class, null);

        $this->expectException(EventException::class);
        $dispatcher->dispatch(new stdClass());
    }

    #[Test]
    public function envelopeRequiredThrowsForPlainEvent(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(true);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/envelope/i');

        $dispatcher->dispatch(new stdClass());
    }

    #[Test]
    public function envelopeRequiredDoesNotThrowForEnvelope(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(true);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope();
        $result = $dispatcher->dispatch($envelope);

        self::assertInstanceOf(EventEnvelope::class, $result);
    }

    #[Test]
    public function dispatchEnvelopeComputesScopeInternal(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn(['billing']);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: 'billing');
        $result = $dispatcher->dispatchEnvelope($envelope);

        self::assertSame(EventScope::Internal, $result->scope);
    }

    #[Test]
    public function dispatchEnvelopeComputesScopeCrossModule(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn(['billing', 'shipping']);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: 'billing');
        $result = $dispatcher->dispatchEnvelope($envelope);

        self::assertSame(EventScope::CrossModule, $result->scope);
    }

    #[Test]
    public function dispatchEnvelopeWithoutOriginModuleSkipsScopeComputation(): void
    {
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard);

        $envelope = $this->createEnvelope();
        $result = $dispatcher->dispatchEnvelope($envelope);

        self::assertSame(EventScope::CrossModule, $result->scope);
    }

    #[Test]
    public function stormPreventedMetricIncremented(): void
    {
        $metrics = new MetricRegistry();
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 1));
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $guard, $metrics);

        $guard->enter(stdClass::class, null);

        try {
            $dispatcher->dispatch(new stdClass());
        } catch (EventException) {
            // expected
        }

        self::assertTrue($metrics->has('pulsar_event_storm_prevented_total'));
    }

    #[Test]
    public function dispatchWithEnvelopeComputesScope(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn(['billing']);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: 'billing');

        // Use dispatch() (not dispatchEnvelope()) to verify inline scope computation
        /** @var EventEnvelope $result */
        $result = $dispatcher->dispatch($envelope);

        self::assertSame(EventScope::Internal, $result->scope);
    }

    #[Test]
    public function dispatchUsesEventTypeForMetrics(): void
    {
        $metrics = new MetricRegistry();
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, $metrics);

        $envelope = $this->createEnvelope(originModule: 'billing');

        $dispatcher->dispatch($envelope);

        $counter = $metrics->counter('pulsar_event_dispatched_total', 'Events dispatched');

        // Scope is Internal because listenerModuleIdsFor returns [] for EventEnvelope::class
        $labelSet = new LabelSet(['event_class' => 'test.event', 'module' => 'billing', 'scope' => EventScope::Internal->value]);

        // Should use eventType 'test.event' not 'Pulsar\Event\EventEnvelope'
        self::assertGreaterThan(0.0, $counter->value($labelSet));
    }

    private function createEnvelope(?string $originModule = null): EventEnvelope
    {
        $metadata = new EventMetadata(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );

        return EventEnvelope::wrap(
            eventType: 'test.event',
            schemaVersion: 1,
            payload: [],
            metadata: $metadata,
            originModule: $originModule,
        );
    }
}

/**
 * @internal Test helper
 */
final class StoppableTestEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
