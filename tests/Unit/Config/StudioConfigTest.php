<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\StudioCollectorConfig;
use Pulsar\Config\StudioConfig;
use Pulsar\Config\StudioRetentionConfig;
use Pulsar\Config\StudioSecurityConfig;
use Pulsar\Config\StudioServerConfig;

#[CoversClass(StudioConfig::class)]
final class StudioConfigTest extends TestCase
{
    /** @var list<string> Env vars set during tests, to be cleaned up */
    private array $envVarsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->envVarsToClean as $key) {
            putenv($key);
        }

        $this->envVarsToClean = [];
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $this->envVarsToClean[] = $key;
    }

    #[Test]
    public function it_has_sensible_defaults(): void
    {
        $config = new StudioConfig();

        self::assertTrue($config->enabled);
        self::assertSame('storage/studio/studio.sqlite', $config->storagePath);
        self::assertInstanceOf(StudioRetentionConfig::class, $config->retention);
        self::assertInstanceOf(StudioSecurityConfig::class, $config->security);
        self::assertInstanceOf(StudioServerConfig::class, $config->server);
        self::assertInstanceOf(StudioCollectorConfig::class, $config->collectors);
        self::assertSame(1.0, $config->samplingRate);
    }

    #[Test]
    public function it_creates_from_array_with_full_data(): void
    {
        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'enabled' => false,
            'storage_path' => 'custom/path/studio.sqlite',
            'retention' => [
                'max_age_days' => 14,
                'max_size_mb' => 1024,
                'vacuum_interval_hours' => 6,
            ],
            'security' => [
                'auth_required' => true,
                'username' => 'admin',
                'password' => 'secret',
            ],
            'server' => [
                'host' => '0.0.0.0',
                'port' => 9090,
            ],
            'collectors' => [
                'http' => ['enabled' => false],
                'database' => ['enabled' => false],
            ],
            'sampling_rate' => 0.5,
        ], $env);

        self::assertFalse($result->enabled);
        self::assertSame('custom/path/studio.sqlite', $result->storagePath);
        self::assertSame(14, $result->retention->maxAgeDays);
        self::assertSame(1024, $result->retention->maxSizeMb);
        self::assertTrue($result->security->authRequired);
        self::assertSame('admin', $result->security->username);
        self::assertSame('0.0.0.0', $result->server->host);
        self::assertSame(9090, $result->server->port);
        self::assertFalse($result->collectors->http);
        self::assertFalse($result->collectors->database);
        self::assertSame(0.5, $result->samplingRate);
    }

    #[Test]
    public function it_uses_defaults_when_array_is_empty(): void
    {
        $env = Environment::load(null);

        $result = StudioConfig::fromArray([], $env);

        self::assertTrue($result->enabled);
        self::assertSame('storage/studio/studio.sqlite', $result->storagePath);
        self::assertSame(7, $result->retention->maxAgeDays);
        self::assertFalse($result->security->authRequired);
        self::assertSame('127.0.0.1', $result->server->host);
        self::assertTrue($result->collectors->http);
        self::assertSame(1.0, $result->samplingRate);
    }

    #[Test]
    public function env_override_for_enabled_true(): void
    {
        $this->setEnv('STUDIO_ENABLED', 'true');

        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'enabled' => false,
        ], $env);

        self::assertTrue($result->enabled);
    }

    #[Test]
    public function env_override_for_enabled_false(): void
    {
        $this->setEnv('STUDIO_ENABLED', 'false');

        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'enabled' => true,
        ], $env);

        self::assertFalse($result->enabled);
    }

    #[Test]
    public function env_override_for_storage_path(): void
    {
        $this->setEnv('STUDIO_STORAGE_PATH', '/tmp/studio.sqlite');

        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'storage_path' => 'original/path.sqlite',
        ], $env);

        self::assertSame('/tmp/studio.sqlite', $result->storagePath);
    }

    #[Test]
    public function env_override_for_sampling_rate(): void
    {
        $this->setEnv('STUDIO_SAMPLING_RATE', '0.25');

        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'sampling_rate' => 1.0,
        ], $env);

        self::assertSame(0.25, $result->samplingRate);
    }

    #[Test]
    public function sampling_rate_is_clamped_to_zero(): void
    {
        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'sampling_rate' => -0.5,
        ], $env);

        self::assertSame(0.0, $result->samplingRate);
    }

    #[Test]
    public function sampling_rate_is_clamped_to_one(): void
    {
        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'sampling_rate' => 2.0,
        ], $env);

        self::assertSame(1.0, $result->samplingRate);
    }

    #[Test]
    public function sampling_rate_handles_numeric_string(): void
    {
        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'sampling_rate' => '0.75',
        ], $env);

        self::assertSame(0.75, $result->samplingRate);
    }

    #[Test]
    public function sampling_rate_defaults_for_non_numeric_value(): void
    {
        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'sampling_rate' => 'invalid',
        ], $env);

        self::assertSame(1.0, $result->samplingRate);
    }

    #[Test]
    public function sampling_rate_env_override_is_clamped(): void
    {
        $this->setEnv('STUDIO_SAMPLING_RATE', '5.0');

        $env = Environment::load(null);

        $result = StudioConfig::fromArray([], $env);

        self::assertSame(1.0, $result->samplingRate);
    }

    #[Test]
    public function it_handles_integer_sampling_rate(): void
    {
        $env = Environment::load(null);

        $result = StudioConfig::fromArray([
            'sampling_rate' => 1,
        ], $env);

        self::assertSame(1.0, $result->samplingRate);
    }
}
