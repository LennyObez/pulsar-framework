<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Config\RiskConfig;

#[CoversClass(RiskConfig::class)]
final class RiskConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new RiskConfig();

        self::assertSame(0.3, $config->lowThreshold);
        self::assertSame(0.7, $config->highThreshold);
        self::assertSame(3600, $config->velocityWindowSeconds);
        self::assertSame(10, $config->velocityMaxCount);
        self::assertSame(50000, $config->velocityMaxAmountMinorUnits);
        self::assertSame(3000, $config->lowValueThresholdMinorUnits);
        self::assertSame('memory', $config->velocityTracker);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = RiskConfig::fromArray([
            'low_threshold' => 0.2,
            'high_threshold' => 0.8,
            'velocity_window_seconds' => 7200,
            'velocity_max_count' => 20,
            'velocity_max_amount_minor_units' => 100000,
            'low_value_threshold_minor_units' => 5000,
            'velocity_tracker' => 'redis',
        ]);

        self::assertSame(0.2, $config->lowThreshold);
        self::assertSame(0.8, $config->highThreshold);
        self::assertSame(7200, $config->velocityWindowSeconds);
        self::assertSame(20, $config->velocityMaxCount);
        self::assertSame(100000, $config->velocityMaxAmountMinorUnits);
        self::assertSame(5000, $config->lowValueThresholdMinorUnits);
        self::assertSame('redis', $config->velocityTracker);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = RiskConfig::fromArray([]);

        self::assertSame(0.3, $config->lowThreshold);
        self::assertSame(0.7, $config->highThreshold);
        self::assertSame(3600, $config->velocityWindowSeconds);
        self::assertSame('memory', $config->velocityTracker);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = RiskConfig::fromArray([
            'low_threshold' => 'not-a-float',
            'high_threshold' => 42,
            'velocity_window_seconds' => 1.5,
            'velocity_max_count' => 'ten',
            'velocity_tracker' => 123,
        ]);

        self::assertSame(0.3, $config->lowThreshold);
        self::assertSame(0.7, $config->highThreshold);
        self::assertSame(3600, $config->velocityWindowSeconds);
        self::assertSame(10, $config->velocityMaxCount);
        self::assertSame('memory', $config->velocityTracker);
    }
}
