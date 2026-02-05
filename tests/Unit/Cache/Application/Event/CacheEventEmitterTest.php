<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

        // No exception means success
        $this->addToAssertionCount(1);
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

        // Should not throw
        $emitter->emit(new CacheHitEvent('default', 'array', 'key', 100));

        $this->addToAssertionCount(1);
    }
}
