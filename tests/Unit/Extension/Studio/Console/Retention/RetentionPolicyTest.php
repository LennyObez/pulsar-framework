<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Retention;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Retention\RetentionPolicy;

#[CoversClass(RetentionPolicy::class)]
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
    public function customValues(): void
    {
        $policy = new RetentionPolicy(
            maxAgeDays: 30,
            maxSizeMb: 2000,
            vacuumIntervalHours: 6,
        );

        self::assertSame(30, $policy->maxAgeDays);
        self::assertSame(2000, $policy->maxSizeMb);
        self::assertSame(6, $policy->vacuumIntervalHours);
    }

    #[Test]
    public function maxSizeBytesConvertsFromMb(): void
    {
        $policy = new RetentionPolicy(maxSizeMb: 100);

        self::assertSame(100 * 1024 * 1024, $policy->maxSizeBytes());
    }

    #[Test]
    public function ageCutoffUsReturnsReasonableValue(): void
    {
        $policy = new RetentionPolicy(maxAgeDays: 7);

        $cutoff = $policy->ageCutoffUs();
        $nowUs = (int) (microtime(true) * 1_000_000.0);
        $sevenDaysUs = 7 * 86400 * 1_000_000;

        // Cutoff should be approximately 7 days ago (within 1 second tolerance)
        self::assertEqualsWithDelta($nowUs - $sevenDaysUs, $cutoff, 1_000_000);
    }
}
