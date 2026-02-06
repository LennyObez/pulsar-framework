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
}
