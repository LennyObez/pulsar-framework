<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\LeakSentinelReport;

#[CoversClass(LeakSentinelReport::class)]
final class LeakSentinelReportTest extends TestCase
{
    #[Test]
    public function from_snapshots_with_stable_memory_passes(): void
    {
        $snapshots = [
            100 => 10_000_000,
            1_000 => 10_100_000,
            5_000 => 10_100_000,
            10_000 => 10_100_000,
        ];

        $report = LeakSentinelReport::fromSnapshots($snapshots, 5.0, 2_097_152);

        self::assertTrue($report->passed);
        // Baseline is second snapshot (10_100_000), growth is 0
        self::assertSame(0, $report->growthBytes);
        self::assertSame(0.0, $report->growthPercent);
    }

    #[Test]
    public function from_snapshots_with_excessive_growth_fails(): void
    {
        $snapshots = [
            100 => 10_000_000,
            1_000 => 10_000_000,
            5_000 => 15_000_000,
            10_000 => 20_000_000,
        ];

        $report = LeakSentinelReport::fromSnapshots($snapshots, 5.0, 2_097_152);

        // Growth: 20M - 10M = 10M (100%), exceeds both 5% and 2MB thresholds
        self::assertFalse($report->passed);
        self::assertSame(10_000_000, $report->growthBytes);
        self::assertEqualsWithDelta(100.0, $report->growthPercent, 0.01);
    }

    #[Test]
    public function passes_when_growth_below_bytes_threshold_but_above_percent(): void
    {
        // Scenario: small baseline so percent is high, but absolute bytes are low
        $snapshots = [
            100 => 100_000,
            1_000 => 100_000,
            5_000 => 108_000,
            10_000 => 110_000,
        ];

        // Growth: 10,000 bytes = 10% — above 5% threshold
        // But 10,000 bytes < 2,097,152 bytes threshold
        // OR logic: passes because bytes threshold not exceeded
        $report = LeakSentinelReport::fromSnapshots($snapshots, 5.0, 2_097_152);

        self::assertTrue($report->passed);
        self::assertSame(10_000, $report->growthBytes);
    }

    #[Test]
    public function passes_when_growth_below_percent_threshold_but_above_bytes(): void
    {
        // Scenario: large baseline so percent is low, but absolute bytes are high
        $snapshots = [
            100 => 100_000_000,
            1_000 => 100_000_000,
            5_000 => 103_000_000,
            10_000 => 104_000_000,
        ];

        // Growth: 4,000,000 bytes = 4% — below 5% threshold
        // But 4,000,000 > 2,097,152 bytes threshold
        // OR logic: passes because percent threshold not exceeded
        $report = LeakSentinelReport::fromSnapshots($snapshots, 5.0, 2_097_152);

        self::assertTrue($report->passed);
        self::assertGreaterThan(2_097_152, $report->growthBytes);
    }

    #[Test]
    public function summary_contains_key_metrics(): void
    {
        $snapshots = [
            100 => 10_000_000,
            1_000 => 10_000_000,
            10_000 => 10_500_000,
        ];

        $report = LeakSentinelReport::fromSnapshots($snapshots, 5.0, 2_097_152);

        self::assertStringContainsString('10000000', $report->summary);
        self::assertStringContainsString('10500000', $report->summary);
        self::assertStringContainsString('PASS', $report->summary);
    }

    #[Test]
    public function summary_shows_fail_when_thresholds_exceeded(): void
    {
        $snapshots = [
            100 => 10_000_000,
            1_000 => 10_000_000,
            10_000 => 20_000_000,
        ];

        $report = LeakSentinelReport::fromSnapshots($snapshots, 5.0, 2_097_152);

        self::assertStringContainsString('FAIL', $report->summary);
    }

    #[Test]
    public function uses_warmup_baseline_when_multiple_snapshots(): void
    {
        // First snapshot is pre-warmup, second is post-warmup baseline
        $snapshots = [
            100 => 8_000_000,   // Pre-warmup
            1_000 => 10_000_000, // Post-warmup baseline
            10_000 => 10_500_000,
        ];

        $report = LeakSentinelReport::fromSnapshots($snapshots, 5.0, 2_097_152);

        // Growth should be measured from second snapshot (10M), not first (8M)
        self::assertSame(500_000, $report->growthBytes);
    }

    #[Test]
    public function single_snapshot_uses_it_as_baseline(): void
    {
        $snapshots = [
            10_000 => 10_000_000,
        ];

        $report = LeakSentinelReport::fromSnapshots($snapshots, 5.0, 2_097_152);

        self::assertSame(0, $report->growthBytes);
        self::assertSame(0.0, $report->growthPercent);
        self::assertTrue($report->passed);
    }
}
