<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Failover;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Failover\ConfigReloadFailoverStrategy;

#[CoversClass(ConfigReloadFailoverStrategy::class)]
final class ConfigReloadFailoverStrategyTest extends TestCase
{
    #[Test]
    public function resolveTargetReloadsConfig(): void
    {
        $strategy = new ConfigReloadFailoverStrategy(
            static fn(): array => ['host' => '10.0.0.50', 'port' => 5432],
        );

        self::assertSame('10.0.0.50', $strategy->resolveTarget());
    }

    #[Test]
    public function nameReturnsConfigReload(): void
    {
        $strategy = new ConfigReloadFailoverStrategy(
            static fn(): array => [],
        );

        self::assertSame('config-reload', $strategy->name());
    }

    #[Test]
    public function missingHostReturnsNull(): void
    {
        $strategy = new ConfigReloadFailoverStrategy(
            static fn(): array => ['port' => 5432],
        );

        self::assertNull($strategy->resolveTarget());
    }

    #[Test]
    public function emptyHostReturnsNull(): void
    {
        $strategy = new ConfigReloadFailoverStrategy(
            static fn(): array => ['host' => ''],
        );

        self::assertNull($strategy->resolveTarget());
    }
}
