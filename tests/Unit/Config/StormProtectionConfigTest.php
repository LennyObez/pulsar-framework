<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\StormProtectionConfig;

#[CoversClass(StormProtectionConfig::class)]
final class StormProtectionConfigTest extends TestCase
{
    #[Test]
    public function test_defaults(): void
    {
        $config = new StormProtectionConfig();

        self::assertSame(32, $config->maxDepth);
        self::assertTrue($config->loopDetection);
        self::assertSame(3, $config->maxRepeatsPerEvent);
    }

    #[Test]
    public function test_fromArray_with_all_values(): void
    {
        $config = StormProtectionConfig::fromArray([
            'max_depth' => 64,
            'loop_detection' => false,
            'max_repeats_per_event' => 10,
        ]);

        self::assertSame(64, $config->maxDepth);
        self::assertFalse($config->loopDetection);
        self::assertSame(10, $config->maxRepeatsPerEvent);
    }

    #[Test]
    public function test_fromArray_with_empty_array_uses_defaults(): void
    {
        $config = StormProtectionConfig::fromArray([]);

        self::assertSame(32, $config->maxDepth);
        self::assertTrue($config->loopDetection);
        self::assertSame(3, $config->maxRepeatsPerEvent);
    }

    #[Test]
    public function test_fromArray_with_invalid_types_uses_defaults(): void
    {
        $config = StormProtectionConfig::fromArray([
            'max_depth' => 'not_an_int',
            'loop_detection' => 'not_a_bool',
            'max_repeats_per_event' => 3.14,
        ]);

        self::assertSame(32, $config->maxDepth);
        self::assertTrue($config->loopDetection);
        self::assertSame(3, $config->maxRepeatsPerEvent);
    }

    #[Test]
    public function test_fromArray_clamps_negative_values(): void
    {
        $config = StormProtectionConfig::fromArray([
            'max_depth' => -5,
            'max_repeats_per_event' => 0,
        ]);

        self::assertSame(1, $config->maxDepth);
        self::assertSame(1, $config->maxRepeatsPerEvent);
    }

    #[Test]
    public function test_fromArray_clamps_excessive_values(): void
    {
        $config = StormProtectionConfig::fromArray([
            'max_depth' => 999_999,
            'max_repeats_per_event' => 500,
        ]);

        self::assertSame(1000, $config->maxDepth);
        self::assertSame(100, $config->maxRepeatsPerEvent);
    }

    #[Test]
    public function test_fromArray_accepts_boundary_values(): void
    {
        $config = StormProtectionConfig::fromArray([
            'max_depth' => 1,
            'max_repeats_per_event' => 1,
        ]);

        self::assertSame(1, $config->maxDepth);
        self::assertSame(1, $config->maxRepeatsPerEvent);

        $configMax = StormProtectionConfig::fromArray([
            'max_depth' => 1000,
            'max_repeats_per_event' => 100,
        ]);

        self::assertSame(1000, $configMax->maxDepth);
        self::assertSame(100, $configMax->maxRepeatsPerEvent);
    }

    #[Test]
    public function test_env_override_max_depth(): void
    {
        putenv('EVENT_STORM_MAX_DEPTH=64');

        $config = StormProtectionConfig::fromArray([], Environment::load());

        self::assertSame(64, $config->maxDepth);
    }

    #[Test]
    public function test_env_override_loop_detection(): void
    {
        putenv('EVENT_STORM_LOOP_DETECTION=false');

        $config = StormProtectionConfig::fromArray([], Environment::load());

        self::assertFalse($config->loopDetection);
    }

    protected function tearDown(): void
    {
        putenv('EVENT_STORM_MAX_DEPTH');
        putenv('EVENT_STORM_LOOP_DETECTION');
        putenv('EVENT_STORM_MAX_REPEATS');
    }
}
