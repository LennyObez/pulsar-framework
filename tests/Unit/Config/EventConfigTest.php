<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EventConfig;

#[CoversClass(EventConfig::class)]
final class EventConfigTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('EVENT_ENABLED');
    }

    protected function tearDown(): void
    {
        putenv('EVENT_ENABLED');
    }

    #[Test]
    public function defaults(): void
    {
        $config = new EventConfig();

        self::assertTrue($config->enabled);
        self::assertSame(32, $config->stormProtection->maxDepth);
        self::assertTrue($config->stormProtection->loopDetection);
        self::assertSame(3, $config->stormProtection->maxRepeatsPerEvent);
    }

    #[Test]
    public function fromArrayWithAllValues(): void
    {
        $env = Environment::load();
        $config = EventConfig::fromArray([
            'enabled' => false,
            'storm_protection' => [
                'max_depth' => 16,
                'loop_detection' => false,
                'max_repeats_per_event' => 5,
            ],
        ], $env);

        self::assertFalse($config->enabled);
        self::assertSame(16, $config->stormProtection->maxDepth);
        self::assertFalse($config->stormProtection->loopDetection);
        self::assertSame(5, $config->stormProtection->maxRepeatsPerEvent);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $env = Environment::load();
        $config = EventConfig::fromArray([], $env);

        self::assertTrue($config->enabled);
        self::assertSame(32, $config->stormProtection->maxDepth);
    }

    #[Test]
    public function envVarOverridesArrayValue(): void
    {
        putenv('EVENT_ENABLED=false');
        $env = Environment::load();

        $config = EventConfig::fromArray(['enabled' => true], $env);

        self::assertFalse($config->enabled);
    }
}
