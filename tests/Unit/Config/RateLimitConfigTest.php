<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RateLimitConfig;

#[CoversClass(RateLimitConfig::class)]
final class RateLimitConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $config = new RateLimitConfig(
            enabled: true,
            defaultLimit: 100,
            defaultWindow: 120,
        );

        self::assertTrue($config->enabled);
        self::assertSame(100, $config->defaultLimit);
        self::assertSame(120, $config->defaultWindow);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = RateLimitConfig::fromArray([
            'enabled' => true,
            'default_limit' => 200,
            'default_window' => 300,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(200, $config->defaultLimit);
        self::assertSame(300, $config->defaultWindow);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $config = RateLimitConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(60, $config->defaultLimit);
        self::assertSame(60, $config->defaultWindow);
    }

    #[Test]
    public function fromArrayDisabled(): void
    {
        $config = RateLimitConfig::fromArray(['enabled' => false]);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayNumericStringValuesCast(): void
    {
        $config = RateLimitConfig::fromArray([
            'default_limit' => '500',
            'default_window' => '120',
        ]);

        self::assertSame(500, $config->defaultLimit);
        self::assertSame(120, $config->defaultWindow);
    }

    #[Test]
    public function fromArrayNonNumericStringFallsBackToDefault(): void
    {
        $config = RateLimitConfig::fromArray([
            'default_limit' => 'invalid',
            'default_window' => 'bad',
        ]);

        self::assertSame(60, $config->defaultLimit);
        self::assertSame(60, $config->defaultWindow);
    }
}
