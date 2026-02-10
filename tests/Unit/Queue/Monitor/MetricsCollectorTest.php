<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Monitor\MetricsCollector;
use Pulsar\Queue\Monitor\ProcessingTimeStats;
use Pulsar\Queue\QueueDriverInterface;

#[CoversClass(MetricsCollector::class)]
#[CoversClass(ProcessingTimeStats::class)]
final class MetricsCollectorTest extends TestCase
{
    private MetricsCollector $collector;

    protected function setUp(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willReturn(5);

        $this->collector = new MetricsCollector($driver);
    }

    #[Test]
    public function recordProcessedIncrementsCounter(): void
    {
        $this->collector->recordProcessed('default', 10.5);
        $this->collector->recordProcessed('default', 20.0);

        self::assertSame(2, $this->collector->processedCount('default'));
    }

    #[Test]
    public function recordFailedIncrementsFailedCounter(): void
    {
        $this->collector->recordFailed('emails');
        $this->collector->recordFailed('emails');
        $this->collector->recordFailed('emails');

        self::assertSame(3, $this->collector->failedCount('emails'));
    }

    #[Test]
    public function pendingCountDelegatesToDriver(): void
    {
        self::assertSame(5, $this->collector->pendingCount('default'));
    }

    #[Test]
    public function processingTimeReturnsZeroStatsWhenEmpty(): void
    {
        $stats = $this->collector->processingTime('empty-queue');

        self::assertSame(0.0, $stats->p50);
        self::assertSame(0.0, $stats->p95);
        self::assertSame(0.0, $stats->p99);
        self::assertSame(0.0, $stats->average);
        self::assertSame(0, $stats->sampleCount);
    }

    #[Test]
    public function processingTimeCalculatesPercentiles(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $this->collector->recordProcessed('default', (float) $i);
        }

        $stats = $this->collector->processingTime('default');

        self::assertSame(100, $stats->sampleCount);
        self::assertSame(50.5, $stats->average);
        self::assertGreaterThan(0.0, $stats->p50);
        self::assertGreaterThan($stats->p50, $stats->p95);
        self::assertGreaterThanOrEqual($stats->p95, $stats->p99);
    }

    #[Test]
    public function processingTimeWithSingleSample(): void
    {
        $this->collector->recordProcessed('single', 42.0);

        $stats = $this->collector->processingTime('single');

        self::assertSame(1, $stats->sampleCount);
        self::assertSame(42.0, $stats->average);
        self::assertSame(42.0, $stats->p50);
    }

    #[Test]
    public function resetClearsAllMetrics(): void
    {
        $this->collector->recordProcessed('default', 10.0);
        $this->collector->recordFailed('default');
        $this->collector->recordDispatched('default');

        $this->collector->reset();

        self::assertSame(0, $this->collector->processedCount('default'));
        self::assertSame(0, $this->collector->failedCount('default'));

        $stats = $this->collector->processingTime('default');
        self::assertSame(0, $stats->sampleCount);
    }

    #[Test]
    public function metricsAreIsolatedPerQueue(): void
    {
        $this->collector->recordProcessed('queue-a', 10.0);
        $this->collector->recordProcessed('queue-b', 20.0);
        $this->collector->recordFailed('queue-a');

        self::assertSame(1, $this->collector->processedCount('queue-a'));
        self::assertSame(1, $this->collector->processedCount('queue-b'));
        self::assertSame(1, $this->collector->failedCount('queue-a'));
        self::assertSame(0, $this->collector->failedCount('queue-b'));
    }
}
