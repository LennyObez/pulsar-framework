<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Retention;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Retention\RetentionPolicy;

#[CoversClass(RetentionPolicy::class)]
final class RetentionPolicyTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $policy = new RetentionPolicy();

        self::assertSame(7, $policy->maxAgeDays);
        self::assertSame(500, $policy->maxSizeMb);
        self::assertSame(24, $policy->vacuumIntervalHours);
    }

    #[Test]
    public function constructsWithCustomValues(): void
    {
        $policy = new RetentionPolicy(
            maxAgeDays: 14,
            maxSizeMb: 1000,
            vacuumIntervalHours: 12,
        );

        self::assertSame(14, $policy->maxAgeDays);
        self::assertSame(1000, $policy->maxSizeMb);
        self::assertSame(12, $policy->vacuumIntervalHours);
    }

    #[Test]
    public function ageCutoffUsReturnsTimestampInMicroseconds(): void
    {
        $policy = new RetentionPolicy(maxAgeDays: 1);

        $cutoff = $policy->ageCutoffUs();

        // Cutoff should be approximately 1 day ago (86400 seconds * 1_000_000)
        $expectedApprox = (int) ((microtime(true) - 86400.0) * 1_000_000.0);

        // Allow 1 second tolerance (1_000_000 microseconds)
        self::assertEqualsWithDelta($expectedApprox, $cutoff, 1_000_000);
    }

    #[Test]
    public function ageCutoffUsScalesWithDays(): void
    {
        $policy1Day = new RetentionPolicy(maxAgeDays: 1);
        $policy7Days = new RetentionPolicy(maxAgeDays: 7);

        $cutoff1Day = $policy1Day->ageCutoffUs();
        $cutoff7Days = $policy7Days->ageCutoffUs();

        // 7-day cutoff should be further in the past (smaller timestamp)
        self::assertLessThan($cutoff1Day, $cutoff7Days);

        // Difference should be approximately 6 days worth of microseconds
        $expectedDiff = 6 * 86400 * 1_000_000;
        $actualDiff = $cutoff1Day - $cutoff7Days;

        self::assertEqualsWithDelta($expectedDiff, $actualDiff, 1_000_000);
    }

    #[Test]
    public function maxSizeBytesConvertsMbToBytes(): void
    {
        $policy = new RetentionPolicy(maxSizeMb: 500);

        self::assertSame(500 * 1024 * 1024, $policy->maxSizeBytes());
    }

    #[Test]
    public function maxSizeBytesWithOneGigabyte(): void
    {
        $policy = new RetentionPolicy(maxSizeMb: 1024);

        // 1024 MB = 1 GB = 1073741824 bytes
        self::assertSame(1073741824, $policy->maxSizeBytes());
    }

    #[Test]
    public function maxSizeBytesWithSmallSize(): void
    {
        $policy = new RetentionPolicy(maxSizeMb: 1);

        self::assertSame(1024 * 1024, $policy->maxSizeBytes());
    }

    #[Test]
    public function ageCutoffUsWithZeroDays(): void
    {
        $policy = new RetentionPolicy(maxAgeDays: 0);

        $cutoff = $policy->ageCutoffUs();
        $now = (int) (microtime(true) * 1_000_000.0);

        // With 0 days, cutoff should be approximately now
        self::assertEqualsWithDelta($now, $cutoff, 1_000_000);
    }
}
