<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Internal\Diagnostics\MemoryTracker;

final class MemoryTrackerTest extends TestCase
{
    #[Test]
    public function snapshotRecordsMemoryState(): void
    {
        $tracker = new MemoryTracker();

        $snapshot = $tracker->snapshot(1);

        self::assertSame(1, $snapshot->requestNumber);
        self::assertGreaterThan(0, $snapshot->usageBytes);
        self::assertGreaterThan(0, $snapshot->peakBytes);
        self::assertSame(1, $tracker->snapshotCount());
    }

    #[Test]
    public function recordExplicitStoresGivenValues(): void
    {
        $tracker = new MemoryTracker();

        $snapshot = $tracker->recordExplicit(1024, 2048, 5);

        self::assertSame(1024, $snapshot->usageBytes);
        self::assertSame(2048, $snapshot->peakBytes);
        self::assertSame(5, $snapshot->requestNumber);
    }

    #[Test]
    public function ringBufferLimitsSnapshots(): void
    {
        $tracker = new MemoryTracker(maxSnapshots: 5);

        for ($i = 1; $i <= 10; $i++) {
            $tracker->recordExplicit($i * 1000, $i * 2000, $i);
        }

        self::assertSame(5, $tracker->snapshotCount());
        // Oldest should be request 6 (first 5 were shifted out)
        $snapshots = $tracker->snapshots();
        self::assertSame(6, $snapshots[0]->requestNumber);
    }

    #[Test]
    public function noLeakDetectedWithStableMemory(): void
    {
        $tracker = new MemoryTracker(minSamplesForDetection: 5, leakThresholdBytes: 1024);

        for ($i = 0; $i < 10; $i++) {
            // Stable memory around 1MB
            $tracker->recordExplicit(1048576, 1048576, $i + 1);
        }

        $report = $tracker->detectLeak();
        self::assertNull($report);
    }

    #[Test]
    public function detectsLeakWithGrowingMemory(): void
    {
        $tracker = new MemoryTracker(
            minSamplesForDetection: 5,
            leakThresholdBytes: 1024,
        );

        $base = 1048576; // 1MB
        for ($i = 0; $i < 10; $i++) {
            $usage = $base + ($i * 500_000); // 500KB growth per request
            $tracker->recordExplicit($usage, $usage + 100_000, $i + 1);
        }

        $report = $tracker->detectLeak();
        self::assertNotNull($report);
        self::assertTrue($report->suspected);
        self::assertGreaterThan(0, $report->growthPerRequestBytes);
        self::assertGreaterThan(0, $report->totalGrowthBytes);
        self::assertSame(10, $report->sampleCount);
    }

    #[Test]
    public function noLeakDetectedWithInsufficientSamples(): void
    {
        $tracker = new MemoryTracker(minSamplesForDetection: 10);

        for ($i = 0; $i < 5; $i++) {
            $tracker->recordExplicit($i * 1000000, $i * 1000000, $i + 1);
        }

        self::assertNull($tracker->detectLeak());
    }

    #[Test]
    public function peakUsageBytesReturnsMaximum(): void
    {
        $tracker = new MemoryTracker();

        $tracker->recordExplicit(1000, 5000, 1);
        $tracker->recordExplicit(2000, 3000, 2);
        $tracker->recordExplicit(1500, 8000, 3);

        self::assertSame(8000, $tracker->peakUsageBytes());
    }

    #[Test]
    public function currentUsageBytesReturnsLatest(): void
    {
        $tracker = new MemoryTracker();

        $tracker->recordExplicit(1000, 2000, 1);
        $tracker->recordExplicit(3000, 4000, 2);

        self::assertSame(3000, $tracker->currentUsageBytes());
    }

    #[Test]
    public function recentSnapshotsReturnsLatestN(): void
    {
        $tracker = new MemoryTracker();

        for ($i = 1; $i <= 10; $i++) {
            $tracker->recordExplicit($i * 100, $i * 200, $i);
        }

        $recent = $tracker->recentSnapshots(3);
        self::assertCount(3, $recent);
        self::assertSame(8, $recent[0]->requestNumber);
        self::assertSame(10, $recent[2]->requestNumber);
    }

    #[Test]
    public function toArrayExportsAllSnapshots(): void
    {
        $tracker = new MemoryTracker();

        $tracker->recordExplicit(1024, 2048, 1);
        $tracker->recordExplicit(2048, 4096, 2);

        $array = $tracker->toArray();
        self::assertCount(2, $array);
        self::assertArrayHasKey('usage_bytes', $array[0]);
        self::assertArrayHasKey('peak_bytes', $array[0]);
        self::assertArrayHasKey('request_number', $array[0]);
        self::assertArrayHasKey('timestamp', $array[0]);
    }

    #[Test]
    public function resetClearsAllSnapshots(): void
    {
        $tracker = new MemoryTracker();

        $tracker->recordExplicit(1024, 2048, 1);
        self::assertSame(1, $tracker->snapshotCount());

        $tracker->reset();
        self::assertSame(0, $tracker->snapshotCount());
    }
}
