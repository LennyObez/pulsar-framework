<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Pool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Pool\PoolConfig;

#[CoversClass(PoolConfig::class)]
final class PoolConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = PoolConfig::fromArray([]);

        self::assertSame(2, $config->minConnections);
        self::assertSame(10, $config->maxConnections);
        self::assertSame(60, $config->idleTimeoutSeconds);
        self::assertSame(3600, $config->maxLifetimeSeconds);
        self::assertSame(30, $config->healthCheckIntervalSeconds);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = PoolConfig::fromArray([
            'min_connections' => 5,
            'max_connections' => 20,
            'idle_timeout_seconds' => 120,
            'max_lifetime_seconds' => 7200,
            'health_check_interval_seconds' => 60,
        ]);

        self::assertSame(5, $config->minConnections);
        self::assertSame(20, $config->maxConnections);
        self::assertSame(120, $config->idleTimeoutSeconds);
        self::assertSame(7200, $config->maxLifetimeSeconds);
        self::assertSame(60, $config->healthCheckIntervalSeconds);
    }

    #[Test]
    public function constructorDefaultValues(): void
    {
        $config = new PoolConfig();

        self::assertSame(2, $config->minConnections);
        self::assertSame(10, $config->maxConnections);
        self::assertSame(60, $config->idleTimeoutSeconds);
        self::assertSame(3600, $config->maxLifetimeSeconds);
        self::assertSame(30, $config->healthCheckIntervalSeconds);
    }

    #[Test]
    public function constructorWithCustomValues(): void
    {
        $config = new PoolConfig(
            minConnections: 4,
            maxConnections: 50,
            idleTimeoutSeconds: 300,
            maxLifetimeSeconds: 1800,
            healthCheckIntervalSeconds: 15,
        );

        self::assertSame(4, $config->minConnections);
        self::assertSame(50, $config->maxConnections);
        self::assertSame(300, $config->idleTimeoutSeconds);
        self::assertSame(1800, $config->maxLifetimeSeconds);
        self::assertSame(15, $config->healthCheckIntervalSeconds);
    }
}
