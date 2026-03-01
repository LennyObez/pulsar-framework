<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
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
use RuntimeException;
use stdClass;

use function assert;

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

    // ---- dispatch() basic behavior ----

    #[Test]
    public function dispatchInvokesRegisteredListenerWithEvent(): void
    {
        $received = null;
        $this->provider->addListener(stdClass::class, static function (stdClass $e) use (&$received): void {
            $received = $e;
        });

        $event = new stdClass();
        $this->dispatcher->dispatch($event);

        self::assertSame($event, $received);
    }

    #[Test]
    public function dispatchReturnsTheSameEventObject(): void
    {
        $event = new stdClass();
        $returned = $this->dispatcher->dispatch($event);

        self::assertSame($event, $returned);
    }

    #[Test]
    public function dispatchWithNoListenersReturnsEventUnmodified(): void
    {
        $event = new stdClass();
        $event->value = 'original';

        $returned = $this->dispatcher->dispatch($event);

        assert($returned instanceof stdClass);
        self::assertSame('original', $returned->value);
    }

    #[Test]
    public function dispatchCallsMultipleListenersInPriorityOrder(): void
    {
        $order = [];

        $this->provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'low';
        }, priority: -10);

        $this->provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'high';
        }, priority: 100);

        $this->provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'medium';
        }, priority: 50);

        $this->dispatcher->dispatch(new stdClass());

        self::assertSame(['high', 'medium', 'low'], $order);
    }

    // ---- Stoppable event propagation ----

    #[Test]
    public function dispatchStopsPropagationWhenEventIsStopped(): void
    {
        $order = [];

        $this->provider->addListener(DispatcherStoppableEvent::class, static function (DispatcherStoppableEvent $e) use (&$order): void {
            $order[] = 'first';
            $e->stop();
        }, priority: 10);

        $this->provider->addListener(DispatcherStoppableEvent::class, static function () use (&$order): void {
            $order[] = 'second';
        }, priority: 0);

        $this->dispatcher->dispatch(new DispatcherStoppableEvent());

        self::assertSame(['first'], $order);
    }

    #[Test]
    public function dispatchDoesNotStopIfStoppableEventIsNotStopped(): void
    {
        $order = [];

        $this->provider->addListener(DispatcherStoppableEvent::class, static function () use (&$order): void {
            $order[] = 'first';
        }, priority: 10);

        $this->provider->addListener(DispatcherStoppableEvent::class, static function () use (&$order): void {
            $order[] = 'second';
        }, priority: 0);

        $this->dispatcher->dispatch(new DispatcherStoppableEvent());

        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function dispatchSkipsAllListenersIfAlreadyStopped(): void
    {
        $called = false;

        $this->provider->addListener(DispatcherStoppableEvent::class, static function () use (&$called): void {
            $called = true;
        });

        $event = new DispatcherStoppableEvent();
        $event->stop(); // Pre-stopped

        $this->dispatcher->dispatch($event);

        self::assertFalse($called);
    }

    // ---- Storm protection ----

    #[Test]
    public function dispatchThrowsEventExceptionWhenStormDepthExceeded(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 2));
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $guard);

        // Occupy two levels
        $guard->enter(stdClass::class);
        $guard->enter(stdClass::class);

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/storm detected/i');

        $dispatcher->dispatch(new stdClass());
    }

    #[Test]
    public function dispatchLeavesStormGuardAfterSuccessfulDispatch(): void
    {
        $this->dispatcher->dispatch(new stdClass());

        self::assertSame(0, $this->stormGuard->currentDepth());
    }

    #[Test]
    public function dispatchLeavesStormGuardEvenWhenListenerThrows(): void
    {
        $this->provider->addListener(stdClass::class, static function (): void {
            throw new RuntimeException('Listener failure');
        });

        try {
            $this->dispatcher->dispatch(new stdClass());
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(0, $this->stormGuard->currentDepth());
    }

    #[Test]
    public function dispatchContinuesAfterListenerExceptionAndRethrows(): void
    {
        $order = [];

        $this->provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'first';
            throw new RuntimeException('First listener failed');
        }, priority: 10);

        $this->provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'second';
        }, priority: 0);

        try {
            $this->dispatcher->dispatch(new stdClass());
            self::fail('Expected RuntimeException to be re-thrown');
        } catch (RuntimeException $e) {
            self::assertSame('First listener failed', $e->getMessage());
        }

        // Both listeners were called despite the first one throwing
        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function dispatchRethrowsFirstExceptionWhenMultipleListenersFail(): void
    {
        $this->provider->addListener(stdClass::class, static function (): void {
            throw new RuntimeException('Error A');
        }, priority: 10);

        $this->provider->addListener(stdClass::class, static function (): void {
            throw new RuntimeException('Error B');
        }, priority: 0);

        try {
            $this->dispatcher->dispatch(new stdClass());
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('Error A', $e->getMessage());
        }
    }

    #[Test]
    public function dispatchLogsListenerException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('error')->with(
            'Event listener threw exception',
            self::callback(static fn(array $ctx): bool => $ctx['event_type'] === stdClass::class && $ctx['error'] === 'Listener blew up'),
        );

        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, null, $logger);

        $this->provider->addListener(stdClass::class, static function (): void {
            throw new RuntimeException('Listener blew up');
        });

        try {
            $dispatcher->dispatch(new stdClass());
        } catch (RuntimeException) {
            // expected
        }
    }

    #[Test]
    public function dispatchEmitsListenerErrorMetric(): void
    {
        $metrics = new MetricRegistry();
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, $metrics);

        $this->provider->addListener(stdClass::class, static function (): void {
            throw new RuntimeException('Metric test');
        });

        try {
            $dispatcher->dispatch(new stdClass());
        } catch (RuntimeException) {
            // expected
        }

        self::assertTrue($metrics->has('pulsar_event_listener_error_total'));
    }

    #[Test]
    public function dispatchUsesStormOverrideFromMetadataProvider(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);
        $metadataProvider->method('stormOverrideFor')->willReturn(100);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn([]);

        // Very low default, but override is 100
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 1));
        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $guard);

        // Enter 1 level (at default max), but override allows more
        $guard->enter(stdClass::class, 100);

        // Should not throw — override raises max depth to 100
        $result = $dispatcher->dispatch(new stdClass());

        self::assertInstanceOf(stdClass::class, $result);
    }

    // ---- Metrics ----

    #[Test]
    public function dispatchEmitsDepthGaugeMetric(): void
    {
        $metrics = new MetricRegistry();
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, $metrics);

        $dispatcher->dispatch(new stdClass());

        self::assertTrue($metrics->has('pulsar_event_dispatch_depth'));
    }

    #[Test]
    public function dispatchEmitsDispatchedTotalCounterForPlainEvent(): void
    {
        $metrics = new MetricRegistry();
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, $metrics);

        $dispatcher->dispatch(new stdClass());

        self::assertTrue($metrics->has('pulsar_event_dispatched_total'));

        $counter = $metrics->counter('pulsar_event_dispatched_total', 'Events dispatched');
        $labels = new LabelSet(['event_class' => stdClass::class, 'module' => '', 'scope' => EventScope::CrossModule->value]);
        self::assertGreaterThan(0.0, $counter->value($labels));
    }

    #[Test]
    public function dispatchEmitsStormPreventedMetricOnStormException(): void
    {
        $metrics = new MetricRegistry();
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 1));
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $guard, $metrics);

        $guard->enter(stdClass::class);

        try {
            $dispatcher->dispatch(new stdClass());
        } catch (EventException) {
            // expected
        }

        self::assertTrue($metrics->has('pulsar_event_storm_prevented_total'));
    }

    #[Test]
    public function dispatchWithoutMetricsDoesNotFail(): void
    {
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, null);
        $result = $dispatcher->dispatch(new stdClass());

        self::assertInstanceOf(stdClass::class, $result);
    }

    // ---- Logging ----

    #[Test]
    public function dispatchLogsDebugMessageWhenLoggerProvided(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            'Dispatching event',
            self::callback(static fn(array $context): bool => $context['event_type'] === stdClass::class),
        );

        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, null, $logger);
        $dispatcher->dispatch(new stdClass());
    }

    #[Test]
    public function dispatchLogsEventTypeForEnvelope(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            'Dispatching event',
            self::callback(static fn(array $context): bool => $context['event_type'] === 'order.placed'),
        );

        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, null, $logger);
        $dispatcher->dispatch($this->createEnvelope(eventType: 'order.placed'));
    }

    // ---- Envelope requirement enforcement ----

    #[Test]
    public function dispatchThrowsWhenPlainEventRequiresEnvelope(): void
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
    public function dispatchDoesNotThrowEnvelopeRequiredForActualEnvelope(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(true);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn([]);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope();
        $result = $dispatcher->dispatch($envelope);

        self::assertInstanceOf(EventEnvelope::class, $result);
    }

    #[Test]
    public function dispatchDoesNotCheckEnvelopeRequirementWhenNoMetadataProvider(): void
    {
        $dispatcher = new EventDispatcher($this->provider, null, $this->stormGuard);

        // No metadata provider => no envelope requirement check => no exception
        $result = $dispatcher->dispatch(new stdClass());

        self::assertInstanceOf(stdClass::class, $result);
    }

    // ---- Scope computation ----

    #[Test]
    public function dispatchComputesScopeInternalWhenAllListenersInSameModule(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn(['billing']);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: 'billing');
        /** @var EventEnvelope $result */
        $result = $dispatcher->dispatch($envelope);

        self::assertSame(EventScope::Internal, $result->scope);
    }

    #[Test]
    public function dispatchComputesScopeCrossModuleWhenListenersInDifferentModules(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn(['billing', 'shipping']);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: 'billing');
        /** @var EventEnvelope $result */
        $result = $dispatcher->dispatch($envelope);

        self::assertSame(EventScope::CrossModule, $result->scope);
    }

    #[Test]
    public function dispatchComputesScopeInternalWhenNoListenerModuleIds(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn([]);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: 'billing');
        /** @var EventEnvelope $result */
        $result = $dispatcher->dispatch($envelope);

        self::assertSame(EventScope::Internal, $result->scope);
    }

    #[Test]
    public function dispatchSkipsScopeComputationWhenEnvelopeHasNoOriginModule(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: null);
        /** @var EventEnvelope $result */
        $result = $dispatcher->dispatch($envelope);

        // Default CrossModule because scope computation is skipped
        self::assertSame(EventScope::CrossModule, $result->scope);
    }

    #[Test]
    public function dispatchSkipsScopeComputationWhenNoMetadataProvider(): void
    {
        $dispatcher = new EventDispatcher($this->provider, null, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: 'billing');
        /** @var EventEnvelope $result */
        $result = $dispatcher->dispatch($envelope);

        // No metadata provider, so scope computation is skipped
        self::assertSame(EventScope::CrossModule, $result->scope);
    }

    #[Test]
    public function dispatchPreservesEnvelopeFieldsDuringScopeComputation(): void
    {
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn(['billing']);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard);

        $envelope = $this->createEnvelope(originModule: 'billing', eventType: 'order.placed');
        /** @var EventEnvelope $result */
        $result = $dispatcher->dispatch($envelope);

        self::assertSame('order.placed', $result->eventType);
        self::assertSame(1, $result->schemaVersion);
        self::assertSame('billing', $result->originModule);
        self::assertSame($envelope->eventId, $result->eventId);
        self::assertSame($envelope->payloadHash, $result->payloadHash);
    }

    // ---- dispatchEnvelope() ----

    #[Test]
    public function dispatchEnvelopeReturnsEventEnvelopeType(): void
    {
        $envelope = $this->createEnvelope();
        $result = $this->dispatcher->dispatchEnvelope($envelope);

        self::assertInstanceOf(EventEnvelope::class, $result);
    }

    #[Test]
    public function dispatchEnvelopeDelegatesToDispatch(): void
    {
        $received = null;
        $this->provider->addListener(EventEnvelope::class, static function (EventEnvelope $e) use (&$received): void {
            $received = $e;
        });

        $envelope = $this->createEnvelope();
        $this->dispatcher->dispatchEnvelope($envelope);

        self::assertInstanceOf(EventEnvelope::class, $received);
    }

    // ---- Envelope metrics labels ----

    #[Test]
    public function dispatchUsesEventTypeAsMetricLabelForEnvelope(): void
    {
        $metrics = new MetricRegistry();
        $metadataProvider = $this->createStub(ListenerMetadataProviderInterface::class);
        $metadataProvider->method('requiresEnvelopeFor')->willReturn(false);
        $metadataProvider->method('stormOverrideFor')->willReturn(null);
        $metadataProvider->method('listenerModuleIdsFor')->willReturn(['billing']);

        $dispatcher = new EventDispatcher($this->provider, $metadataProvider, $this->stormGuard, $metrics);

        $envelope = $this->createEnvelope(originModule: 'billing', eventType: 'order.placed');
        $dispatcher->dispatch($envelope);

        $counter = $metrics->counter('pulsar_event_dispatched_total', 'Events dispatched');
        $labels = new LabelSet(['event_class' => 'order.placed', 'module' => 'billing', 'scope' => EventScope::Internal->value]);

        self::assertGreaterThan(0.0, $counter->value($labels));
    }

    #[Test]
    public function dispatchUsesEventClassNameAsMetricLabelForPlainEvent(): void
    {
        $metrics = new MetricRegistry();
        $dispatcher = new EventDispatcher($this->provider, $this->provider, $this->stormGuard, $metrics);

        $dispatcher->dispatch(new stdClass());

        $counter = $metrics->counter('pulsar_event_dispatched_total', 'Events dispatched');
        $labels = new LabelSet(['event_class' => stdClass::class, 'module' => '', 'scope' => EventScope::CrossModule->value]);

        self::assertGreaterThan(0.0, $counter->value($labels));
    }

    // ---- Edge: storm exception still re-throws ----

    #[Test]
    public function dispatchRethrowsStormExceptionAfterRecordingMetric(): void
    {
        $metrics = new MetricRegistry();
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 1));
        $guard->enter(stdClass::class);

        $dispatcher = new EventDispatcher($this->provider, $this->provider, $guard, $metrics);

        try {
            $dispatcher->dispatch(new stdClass());
            self::fail('Expected EventException');
        } catch (EventException $e) {
            self::assertStringContainsString('storm detected', $e->getMessage());
        }

        // Metric was recorded before the exception was re-thrown
        self::assertTrue($metrics->has('pulsar_event_storm_prevented_total'));
    }

    // ---- Helpers ----

    private function createEnvelope(
        ?string $originModule = null,
        string $eventType = 'test.event',
    ): EventEnvelope {
        $metadata = new EventMetadata(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );

        return EventEnvelope::wrap(
            eventType: $eventType,
            schemaVersion: 1,
            payload: ['key' => 'value'],
            metadata: $metadata,
            originModule: $originModule,
        );
    }
}

/**
 * @internal Test helper for stoppable event dispatch
 */
final class DispatcherStoppableEvent implements StoppableEventInterface
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
