<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioConfig;

final class StudioConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreApplied(): void
    {
        $config = new StudioConfig();

        self::assertTrue($config->enabled);
        self::assertSame('storage/studio/studio.sqlite', $config->storagePath);
        self::assertSame('sqlite', $config->storeBackend);
        self::assertSame(1.0, $config->samplingRate);
    }

    #[Test]
    public function fromArrayReadsEnabledFlag(): void
    {
        $env = Environment::load();
        $config = StudioConfig::fromArray(['enabled' => false], $env);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayReadsStoragePath(): void
    {
        $env = Environment::load();
        $config = StudioConfig::fromArray(['storage_path' => '/custom/path.db'], $env);

        self::assertSame('/custom/path.db', $config->storagePath);
    }

    #[Test]
    public function fromArrayReadsStoreBackend(): void
    {
        $env = Environment::load();
        $config = StudioConfig::fromArray(['store' => 'database'], $env);

        self::assertSame('database', $config->storeBackend);
    }

    #[Test]
    public function fromArrayDefaultsInvalidStoreToSqlite(): void
    {
        $env = Environment::load();
        $config = StudioConfig::fromArray(['store' => 'invalid'], $env);

        self::assertSame('sqlite', $config->storeBackend);
    }

    #[Test]
    public function fromArrayClampsSamplingRate(): void
    {
        $env = Environment::load();
        $config = StudioConfig::fromArray(['sampling_rate' => 2.5], $env);

        self::assertSame(1.0, $config->samplingRate);
    }

    #[Test]
    public function fromArrayClampsNegativeSamplingRate(): void
    {
        $env = Environment::load();
        $config = StudioConfig::fromArray(['sampling_rate' => -0.5], $env);

        self::assertSame(0.0, $config->samplingRate);
    }

    #[Test]
    public function fromArrayCreatesNestedConfigs(): void
    {
        $env = Environment::load();
        $config = StudioConfig::fromArray([
            'retention' => ['max_age_days' => 14],
            'security' => ['auth_required' => true],
            'server' => ['port' => 9000],
            'collectors' => ['http' => ['enabled' => false]],
        ], $env);

        self::assertSame(14, $config->retention->maxAgeDays);
        self::assertTrue($config->security->authRequired);
        self::assertSame(9000, $config->server->port);
        self::assertFalse($config->collectors->http);
    }
}
