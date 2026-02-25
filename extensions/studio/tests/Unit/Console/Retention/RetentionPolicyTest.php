<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Retention;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Retention\RetentionPolicy;

final class RetentionPolicyTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $policy = new RetentionPolicy();

        self::assertSame(7, $policy->maxAgeDays);
        self::assertSame(500, $policy->maxSizeMb);
        self::assertSame(24, $policy->vacuumIntervalHours);
    }

    #[Test]
    public function maxSizeBytesConvertsCorrectly(): void
    {
        $policy = new RetentionPolicy(maxSizeMb: 100);

        self::assertSame(100 * 1024 * 1024, $policy->maxSizeBytes());
    }

    #[Test]
    public function ageCutoffUsReturnsReasonableValue(): void
    {
        $policy = new RetentionPolicy(maxAgeDays: 7);
        $cutoff = $policy->ageCutoffUs();

        // Cutoff should be about 7 days ago in microseconds
        $expectedApprox = (int) ((microtime(true) - 7 * 86400) * 1_000_000);
        $tolerance = 2_000_000; // 2 seconds tolerance

        self::assertLessThan($tolerance, abs($cutoff - $expectedApprox));
    }

    #[Test]
    public function ageCutoffUsDecreasesWithMoreDays(): void
    {
        $policy7 = new RetentionPolicy(maxAgeDays: 7);
        $policy30 = new RetentionPolicy(maxAgeDays: 30);

        self::assertGreaterThan($policy30->ageCutoffUs(), $policy7->ageCutoffUs());
    }

    #[Test]
    public function customValues(): void
    {
        $policy = new RetentionPolicy(maxAgeDays: 30, maxSizeMb: 1024, vacuumIntervalHours: 12);

        self::assertSame(30, $policy->maxAgeDays);
        self::assertSame(1024, $policy->maxSizeMb);
        self::assertSame(12, $policy->vacuumIntervalHours);
    }
}
