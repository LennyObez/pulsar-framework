<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\Exception\ConfigException;

#[CoversClass(ConfigRepository::class)]
final class ConfigRepositoryTest extends TestCase
{
    #[Test]
    public function storesAndRetrievesByClass(): void
    {
        $repo = new ConfigRepository();
        $config = new AppConfig(
            name: 'Test',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        );

        $repo->set($config);
        $retrieved = $repo->get(AppConfig::class);

        self::assertSame($config, $retrieved);
    }

    #[Test]
    public function throwsForMissingConfig(): void
    {
        $repo = new ConfigRepository();

        $this->expectException(ConfigException::class);
        $_ = $repo->get(AppConfig::class);
    }

    #[Test]
    public function hasReturnsAccurately(): void
    {
        $repo = new ConfigRepository();

        self::assertFalse($repo->has(AppConfig::class));

        $repo->set(new AppConfig(
            name: 'Test',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        ));

        self::assertTrue($repo->has(AppConfig::class));
    }

    #[Test]
    public function setOverwritesPreviousConfig(): void
    {
        $repo = new ConfigRepository();

        $config1 = new AppConfig(
            name: 'First',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        );
        $config2 = new AppConfig(
            name: 'Second',
            mode: EnvironmentMode::Production,
            debug: false,
            timezone: 'America/New_York',
            locale: 'fr',
        );

        $repo->set($config1);
        $repo->set($config2);

        $retrieved = $repo->get(AppConfig::class);

        self::assertSame($config2, $retrieved);
        self::assertSame('Second', $retrieved->name);
    }

    #[Test]
    public function storesMultipleConfigTypes(): void
    {
        $repo = new ConfigRepository();

        $appConfig = new AppConfig(
            name: 'Test',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        );

        $otherConfig = new class {
            public string $key = 'value';
        };

        $repo->set($appConfig);
        $repo->set($otherConfig);

        self::assertTrue($repo->has(AppConfig::class));
        self::assertTrue($repo->has($otherConfig::class));
        self::assertSame($appConfig, $repo->get(AppConfig::class));
        self::assertSame($otherConfig, $repo->get($otherConfig::class));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredClass(): void
    {
        $repo = new ConfigRepository();

        self::assertFalse($repo->has('NonExistent\Config\Class'));
    }

    #[Test]
    public function getReturnsExactSameInstance(): void
    {
        $repo = new ConfigRepository();
        $config = new AppConfig(
            name: 'Test',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        );

        $repo->set($config);

        // get() must return the exact same instance, not a copy
        self::assertSame($config, $repo->get(AppConfig::class));
        self::assertSame($repo->get(AppConfig::class), $repo->get(AppConfig::class));
    }
}
