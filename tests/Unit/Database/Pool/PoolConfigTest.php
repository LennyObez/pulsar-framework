<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Pool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Pool\PoolConfig;

use function dirname;

#[CoversClass(PoolConfig::class)]
final class PoolConfigTest extends TestCase
{
    /**
     * `config/database.php` has no `pool` section, and that is the point.
     *
     * It carried one until 1.0.0-rc.12. The section was parsed into this object and
     * then read by nothing: no wiring built a pool from it on any runtime, so an
     * operator who sized `max_connections` there changed nothing at all. The section,
     * its parser and its documentation are gone. A pool is constructed by the
     * application that wants one, which is the only arrangement in which these numbers
     * take effect — so a `pool` key reappearing in the shipped config is a knob
     * connected to nothing coming back.
     */
    #[Test]
    public function theShippedConfigFileHasNoPoolSection(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 4) . '/config/database.php';

        self::assertArrayNotHasKey('pool', $config);
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
