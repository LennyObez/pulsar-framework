<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Internal\Diagnostics\MemorySnapshot;

final class MemorySnapshotTest extends TestCase
{
    #[Test]
    public function formattedUsageInBytes(): void
    {
        $snapshot = new MemorySnapshot(512, 1024, 1, 1.0);
        self::assertSame('512 B', $snapshot->formattedUsage());
    }

    #[Test]
    public function formattedUsageInKilobytes(): void
    {
        $snapshot = new MemorySnapshot(2048, 4096, 1, 1.0);
        self::assertSame('2.0 KB', $snapshot->formattedUsage());
    }

    #[Test]
    public function formattedUsageInMegabytes(): void
    {
        $snapshot = new MemorySnapshot(2 * 1024 * 1024, 4 * 1024 * 1024, 1, 1.0);
        self::assertSame('2.0 MB', $snapshot->formattedUsage());
    }

    #[Test]
    public function formattedPeakShowsPeakValue(): void
    {
        $snapshot = new MemorySnapshot(1024, 3 * 1024 * 1024, 1, 1.0);
        self::assertSame('3.0 MB', $snapshot->formattedPeak());
    }

    #[Test]
    public function propertiesAreImmutable(): void
    {
        $snapshot = new MemorySnapshot(
            usageBytes: 65536,
            peakBytes: 131072,
            requestNumber: 42,
            timestamp: 1700000000.123,
        );

        self::assertSame(65536, $snapshot->usageBytes);
        self::assertSame(131072, $snapshot->peakBytes);
        self::assertSame(42, $snapshot->requestNumber);
        self::assertEqualsWithDelta(1700000000.123, $snapshot->timestamp, 0.001);
    }
}
