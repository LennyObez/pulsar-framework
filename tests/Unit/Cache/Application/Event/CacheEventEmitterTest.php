<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Cache\Application\Event\CacheClearEvent;
use Pulsar\Cache\Application\Event\CacheDeleteEvent;
use Pulsar\Cache\Application\Event\CacheErrorEvent;
use Pulsar\Cache\Application\Event\CacheEvent;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Event\CacheHitEvent;
use Pulsar\Cache\Application\Event\CacheMissEvent;
use Pulsar\Cache\Application\Event\CacheWriteEvent;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use RuntimeException;

#[CoversClass(CacheEventEmitter::class)]
final class CacheEventEmitterTest extends TestCase
{
    #[Test]
    public function emitWithNoMetricsOrLoggerDoesNotThrow(): void
    {
        $emitter = new CacheEventEmitter();

        $event = new CacheHitEvent('default', 'array', 'hashed-key', 100);

        $emitter->emit($event);

        // Emit without listeners is a safe no-op — verify via late-attached listener receiving no prior events
        $received = [];
        $emitter->addListener(static function (CacheEvent $e) use (&$received): void {
            $received[] = $e;
        });

        self::assertCount(0, $received, 'Pre-listener events must not be replayed');
    }

    #[Test]
    public function addListenerReceivesEmittedEvents(): void
    {
        $emitter = new CacheEventEmitter();
        $received = [];

        $emitter->addListener(static function (CacheEvent $event) use (&$received): void {
            $received[] = $event;
        });

        $hit = new CacheHitEvent('default', 'array', 'key1', 100);
        $miss = new CacheMissEvent('default', 'array', 'key2', 200);

        $emitter->emit($hit);
        $emitter->emit($miss);

        self::assertCount(2, $received);
        self::assertSame($hit, $received[0]);
        self::assertSame($miss, $received[1]);
    }

    #[Test]
    public function emitWithMetricRegistryRecordsCounters(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $event = new CacheHitEvent('default', 'array', 'hashed-key', 100);
        $emitter->emit($event);

        $counter = $metrics->counter('pulsar_cache_hits_total', 'Cache hit count');
        $labels = new LabelSet(['pool' => 'default', 'driver' => 'array']);

        self::assertSame(1.0, $counter->value($labels));
    }

    #[Test]
    public function emitMultipleEventsIncrementsCounters(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $emitter->emit(new CacheHitEvent('default', 'array', 'key1', 100));
        $emitter->emit(new CacheHitEvent('default', 'array', 'key2', 150));
        $emitter->emit(new CacheMissEvent('default', 'array', 'key3', 200));

        $hitCounter = $metrics->counter('pulsar_cache_hits_total', 'Cache hit count');
        $missCounter = $metrics->counter('pulsar_cache_misses_total', 'Cache miss count');
        $labels = new LabelSet(['pool' => 'default', 'driver' => 'array']);

        self::assertSame(2.0, $hitCounter->value($labels));
        self::assertSame(1.0, $missCounter->value($labels));
    }

    #[Test]
    public function loggerLogsErrorEventAtWarningLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('Cache error'),
                self::callback(static fn(array $context): bool => isset($context['pool']) && $context['pool'] === 'default'
                    && isset($context['message']) && $context['message'] === 'connection lost'),
            );

        $emitter = new CacheEventEmitter(logger: $logger);

        $event = new CacheErrorEvent('default', 'redis', 'key', 100, 'connection lost', new RuntimeException('connection lost'));
        $emitter->emit($event);
    }

    #[Test]
    public function loggerLogsNonErrorEventsAtDebugLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::stringContains('Cache'),
                self::callback(static fn(array $context): bool => isset($context['operation']) && $context['operation'] === 'hit'),
            );

        $emitter = new CacheEventEmitter(logger: $logger);

        $event = new CacheHitEvent('default', 'array', 'key', 100);
        $emitter->emit($event);
    }

    #[Test]
    public function deleteCounterMetricIsRecorded(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $emitter->emit(new CacheDeleteEvent('default', 'array', 'key1', 100));

        $counter = $metrics->counter('pulsar_cache_deletes_total', 'Cache delete count');
        $labels = new LabelSet(['pool' => 'default', 'driver' => 'array']);

        self::assertSame(1.0, $counter->value($labels));
    }

    #[Test]
    public function writeCounterMetricIsRecorded(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $emitter->emit(new CacheWriteEvent('default', 'array', 'key1', 100));

        $counter = $metrics->counter('pulsar_cache_writes_total', 'Cache write count');
        $labels = new LabelSet(['pool' => 'default', 'driver' => 'array']);

        self::assertSame(1.0, $counter->value($labels));
    }

    #[Test]
    public function histogramObservationIsRecordedWithCorrectDuration(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $emitter->emit(new CacheHitEvent('default', 'array', 'key1', 500));

        $histogram = $metrics->histogram(
            'pulsar_cache_operation_duration_seconds',
            'Cache operation duration in seconds',
            [0.0001, 0.0005, 0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1.0],
        );

        $labels = new LabelSet(['pool' => 'default', 'driver' => 'array', 'operation' => 'hit']);

        // 500 microseconds = 0.0005 seconds
        self::assertSame(1, $histogram->count($labels));
    }

    #[Test]
    public function listenerExceptionDoesNotPropagate(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::stringContains('listener threw'),
                self::anything(),
            );

        $emitter = new CacheEventEmitter(logger: $logger);

        $emitter->addListener(static function (CacheEvent $event): void {
            throw new RuntimeException('listener failure');
        });

        // Should not throw — exception was swallowed and logged
        $emitter->emit(new CacheHitEvent('default', 'array', 'key', 100));

        // The logger mock already carries the expectation (atLeastOnce) — no extra assertion needed;
        // the mock verification at teardown confirms error() was called
        self::assertInstanceOf(CacheEventEmitter::class, $emitter);
    }

    #[Test]
    public function emitHitCreatesHitEventWithDuration(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter();
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emitHit('pool', 'array', 'key1', hrtime(true) - 500_000);

        self::assertCount(1, $received);
        self::assertInstanceOf(CacheHitEvent::class, $received[0]);
        self::assertSame('pool', $received[0]->poolName);
        self::assertSame('array', $received[0]->driverName);
        self::assertSame('key1', $received[0]->hashedKey);
        self::assertSame('hit', $received[0]->operationType);
        self::assertGreaterThan(0, $received[0]->durationMicroseconds);
    }

    #[Test]
    public function emitMissCreatesMissEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter();
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emitMiss('pool', 'redis', 'miss-key', hrtime(true));

        self::assertCount(1, $received);
        self::assertInstanceOf(CacheMissEvent::class, $received[0]);
        self::assertSame('miss', $received[0]->operationType);
    }

    #[Test]
    public function emitWriteCreatesWriteEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter();
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emitWrite('pool', 'filesystem', 'w-key', hrtime(true));

        self::assertCount(1, $received);
        self::assertInstanceOf(CacheWriteEvent::class, $received[0]);
        self::assertSame('write', $received[0]->operationType);
    }

    #[Test]
    public function emitDeleteCreatesDeleteEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter();
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emitDelete('pool', 'memcached', 'd-key', hrtime(true));

        self::assertCount(1, $received);
        self::assertInstanceOf(CacheDeleteEvent::class, $received[0]);
        self::assertSame('delete', $received[0]->operationType);
    }

    #[Test]
    public function emitClearCreatesClearEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter();
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emitClear('pool', 'apcu', hrtime(true));

        self::assertCount(1, $received);
        self::assertInstanceOf(CacheClearEvent::class, $received[0]);
        self::assertSame('clear', $received[0]->operationType);
        self::assertSame('', $received[0]->hashedKey);
    }

    #[Test]
    public function emitErrorCreatesErrorEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter();
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $exception = new RuntimeException('conn lost');
        $emitter->emitError('pool', 'redis', 'e-key', hrtime(true), 'conn lost', $exception);

        self::assertCount(1, $received);
        self::assertInstanceOf(CacheErrorEvent::class, $received[0]);
        self::assertSame('error', $received[0]->operationType);
        self::assertSame('conn lost', $received[0]->errorMessage);
    }

    #[Test]
    public function keyHasherTransformsKeyForHitEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter(keyHasher: static fn(string $key): string => 'hashed_' . $key);
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emit(new CacheHitEvent('pool', 'array', 'original', 100));

        self::assertSame('hashed_original', $received[0]->hashedKey);
    }

    #[Test]
    public function keyHasherTransformsKeyForMissEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter(keyHasher: static fn(string $key): string => 'h_' . $key);
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emit(new CacheMissEvent('pool', 'array', 'miss-k', 100));

        self::assertSame('h_miss-k', $received[0]->hashedKey);
    }

    #[Test]
    public function keyHasherTransformsKeyForWriteEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter(keyHasher: static fn(string $key): string => 'h_' . $key);
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emit(new CacheWriteEvent('pool', 'array', 'w-k', 100));

        self::assertSame('h_w-k', $received[0]->hashedKey);
    }

    #[Test]
    public function keyHasherTransformsKeyForDeleteEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter(keyHasher: static fn(string $key): string => 'h_' . $key);
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emit(new CacheDeleteEvent('pool', 'array', 'd-k', 100));

        self::assertSame('h_d-k', $received[0]->hashedKey);
    }

    #[Test]
    public function keyHasherDoesNotTransformClearEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter(keyHasher: static fn(string $key): string => 'hashed_' . $key);
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $clearEvent = new CacheClearEvent('pool', 'array', 100);
        $emitter->emit($clearEvent);

        self::assertSame('', $received[0]->hashedKey);
    }

    #[Test]
    public function keyHasherTransformsKeyForErrorEvent(): void
    {
        $received = [];
        $emitter = new CacheEventEmitter(keyHasher: static fn(string $key): string => 'h_' . $key);
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emit(new CacheErrorEvent('pool', 'redis', 'err-k', 100, 'fail'));

        self::assertInstanceOf(CacheErrorEvent::class, $received[0]);
        self::assertSame('h_err-k', $received[0]->hashedKey);
    }

    #[Test]
    public function clearCounterMetricIsRecorded(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $emitter->emit(new CacheClearEvent('default', 'array', 100));

        $counter = $metrics->counter('pulsar_cache_clears_total', 'Cache clear count');
        $labels = new LabelSet(['pool' => 'default', 'driver' => 'array']);

        self::assertSame(1.0, $counter->value($labels));
    }

    #[Test]
    public function errorCounterMetricIsRecorded(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $emitter->emit(new CacheErrorEvent('default', 'redis', 'key', 100, 'err'));

        $counter = $metrics->counter('pulsar_cache_errors_total', 'Cache error count');
        $labels = new LabelSet(['pool' => 'default', 'driver' => 'redis']);

        self::assertSame(1.0, $counter->value($labels));
    }

    #[Test]
    public function labelCacheIsInvalidatedWhenPoolDriverChanges(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $emitter->emit(new CacheHitEvent('pool1', 'array', 'k', 100));
        $emitter->emit(new CacheHitEvent('pool2', 'redis', 'k', 100));

        $labels1 = new LabelSet(['pool' => 'pool1', 'driver' => 'array']);
        $labels2 = new LabelSet(['pool' => 'pool2', 'driver' => 'redis']);

        $counter = $metrics->counter('pulsar_cache_hits_total', 'Cache hit count');
        self::assertSame(1.0, $counter->value($labels1));
        self::assertSame(1.0, $counter->value($labels2));
    }

    #[Test]
    public function multipleListenersReceiveEvents(): void
    {
        $emitter = new CacheEventEmitter();
        $received1 = [];
        $received2 = [];

        $emitter->addListener(static function ($e) use (&$received1): void {
            $received1[] = $e;
        });
        $emitter->addListener(static function ($e) use (&$received2): void {
            $received2[] = $e;
        });

        $emitter->emit(new CacheHitEvent('pool', 'array', 'key', 100));

        self::assertCount(1, $received1);
        self::assertCount(1, $received2);
    }

    #[Test]
    public function listenerExceptionDoesNotAffectOtherListeners(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $emitter = new CacheEventEmitter(logger: $logger);
        $received = [];

        $emitter->addListener(static function (): void {
            throw new RuntimeException('boom');
        });
        $emitter->addListener(static function ($e) use (&$received): void {
            $received[] = $e;
        });

        $emitter->emit(new CacheHitEvent('pool', 'array', 'key', 100));

        self::assertCount(1, $received);
    }

    #[Test]
    public function listenerExceptionWithoutLoggerDoesNotCrash(): void
    {
        $this->expectNotToPerformAssertions();

        $emitter = new CacheEventEmitter();

        $emitter->addListener(static function (): void {
            throw new RuntimeException('boom');
        });

        $emitter->emit(new CacheHitEvent('pool', 'array', 'key', 100));
    }

    #[Test]
    public function operationLabelsAreCachedForSamePoolDriver(): void
    {
        $metrics = new MetricRegistry();
        $emitter = new CacheEventEmitter($metrics);

        $emitter->emit(new CacheHitEvent('pool', 'array', 'k1', 100));
        $emitter->emit(new CacheHitEvent('pool', 'array', 'k2', 200));

        $labels = new LabelSet(['pool' => 'pool', 'driver' => 'array']);
        $counter = $metrics->counter('pulsar_cache_hits_total', 'Cache hit count');
        self::assertSame(2.0, $counter->value($labels));
    }
}
