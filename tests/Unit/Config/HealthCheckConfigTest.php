<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\HealthCheckConfig;

#[CoversClass(HealthCheckConfig::class)]
final class HealthCheckConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new HealthCheckConfig();

        self::assertSame(30, $config->intervalSeconds);
        self::assertSame(5, $config->timeoutSeconds);
    }

    #[Test]
    public function constructorWithCustomValues(): void
    {
        $config = new HealthCheckConfig(intervalSeconds: 60, timeoutSeconds: 10);

        self::assertSame(60, $config->intervalSeconds);
        self::assertSame(10, $config->timeoutSeconds);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = HealthCheckConfig::fromArray([
            'interval_seconds' => 15,
            'timeout_seconds' => 3,
        ]);

        self::assertSame(15, $config->intervalSeconds);
        self::assertSame(3, $config->timeoutSeconds);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $config = HealthCheckConfig::fromArray([]);

        self::assertSame(30, $config->intervalSeconds);
        self::assertSame(5, $config->timeoutSeconds);
    }

    #[Test]
    public function fromArrayPartialData(): void
    {
        $config = HealthCheckConfig::fromArray([
            'interval_seconds' => 45,
        ]);

        self::assertSame(45, $config->intervalSeconds);
        self::assertSame(5, $config->timeoutSeconds);
    }
}
