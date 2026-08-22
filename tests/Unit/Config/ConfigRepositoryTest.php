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
use ReflectionClass;

use function sprintf;

#[CoversClass(ConfigRepository::class)]
final class ConfigRepositoryTest extends TestCase
{
    /** @return class-string */
    private static function classString(string $name): string
    {
        /** @var class-string */
        return $name;
    }

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

        self::assertFalse($repo->has(self::classString('NonExistent\Config\Class')));
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

    /**
     * ConfigRepository must never grow magic methods that
     * participate in serialization or destruction. `__wakeup` /
     * `__unserialize` turn deserialization into instantiation;
     * `__destruct` lets an attacker trigger code simply by
     * deserializing a finalized payload. The list is
     * intentionally narrow — `__construct`, `__toString` etc.
     * remain allowed because they cannot be reached from a
     * crafted serialized payload alone.
     *
     * The test pins the absence, so a PR that adds one of these
     * methods fails CI instead of quietly opening the gadget.
     */
    #[Test]
    public function repositoryHasNoSerializationMagicMethods(): void
    {
        $reflection = new ReflectionClass(ConfigRepository::class);

        foreach (['__wakeup', '__unserialize', '__serialize', '__destruct'] as $method) {
            self::assertFalse(
                $reflection->hasMethod($method),
                sprintf(
                    'ConfigRepository must not declare %s — it would re-open F26.2 (deserialization as instantiation).',
                    $method,
                ),
            );
        }
    }
}
