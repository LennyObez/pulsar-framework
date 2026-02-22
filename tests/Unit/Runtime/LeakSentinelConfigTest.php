<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\LeakSentinelConfig;

#[CoversClass(LeakSentinelConfig::class)]
final class LeakSentinelConfigTest extends TestCase
{
    #[Test]
    public function it_has_sensible_defaults(): void
    {
        $config = new LeakSentinelConfig();

        self::assertSame(10_000, $config->totalRequests);
        self::assertSame([100, 1_000, 5_000, 10_000], $config->snapshotPoints);
        self::assertSame(5.0, $config->growthPercentThreshold);
        self::assertSame(2_097_152, $config->growthBytesThreshold);
    }

    #[Test]
    public function it_accepts_custom_values(): void
    {
        $config = new LeakSentinelConfig(
            totalRequests: 5_000,
            snapshotPoints: [50, 500, 2_500, 5_000],
            growthPercentThreshold: 3.0,
            growthBytesThreshold: 1_048_576,
        );

        self::assertSame(5_000, $config->totalRequests);
        self::assertSame([50, 500, 2_500, 5_000], $config->snapshotPoints);
        self::assertSame(3.0, $config->growthPercentThreshold);
        self::assertSame(1_048_576, $config->growthBytesThreshold);
    }

    #[Test]
    public function from_array_creates_config_with_custom_values(): void
    {
        $config = LeakSentinelConfig::fromArray([
            'total_requests' => 20_000,
            'snapshot_points' => [200, 2_000, 10_000, 20_000],
            'growth_percent_threshold' => 10.0,
            'growth_bytes_threshold' => 4_194_304,
        ]);

        self::assertSame(20_000, $config->totalRequests);
        self::assertSame([200, 2_000, 10_000, 20_000], $config->snapshotPoints);
        self::assertSame(10.0, $config->growthPercentThreshold);
        self::assertSame(4_194_304, $config->growthBytesThreshold);
    }

    #[Test]
    public function from_array_uses_defaults_for_missing_keys(): void
    {
        $config = LeakSentinelConfig::fromArray([]);

        self::assertSame(10_000, $config->totalRequests);
        self::assertSame([100, 1_000, 5_000, 10_000], $config->snapshotPoints);
        self::assertSame(5.0, $config->growthPercentThreshold);
        self::assertSame(2_097_152, $config->growthBytesThreshold);
    }

    #[Test]
    public function from_array_uses_defaults_for_wrong_types(): void
    {
        $config = LeakSentinelConfig::fromArray([
            'total_requests' => 'not-an-int',
            'snapshot_points' => 'not-an-array',
            'growth_percent_threshold' => 'not-a-float',
            'growth_bytes_threshold' => 'not-an-int',
        ]);

        self::assertSame(10_000, $config->totalRequests);
        self::assertSame([100, 1_000, 5_000, 10_000], $config->snapshotPoints);
        self::assertSame(5.0, $config->growthPercentThreshold);
        self::assertSame(2_097_152, $config->growthBytesThreshold);
    }

    #[Test]
    public function from_array_filters_non_int_snapshot_points(): void
    {
        $config = LeakSentinelConfig::fromArray([
            'snapshot_points' => [100, 'invalid', 500, null, 1_000],
        ]);

        self::assertSame([100, 500, 1_000], $config->snapshotPoints);
    }
}
