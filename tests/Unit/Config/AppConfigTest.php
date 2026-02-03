<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AppConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;

#[CoversClass(AppConfig::class)]
final class AppConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_appconfig_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);

        // Clear env vars that may interfere
        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');

        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function constructsFromArray(): void
    {
        $env = Environment::load();

        $config = AppConfig::fromArray([
            'name' => 'MyApp',
            'env' => 'production',
            'debug' => false,
            'timezone' => 'America/New_York',
            'locale' => 'fr',
        ], $env);

        self::assertSame('MyApp', $config->name);
        self::assertSame(EnvironmentMode::Production, $config->mode);
        self::assertFalse($config->debug);
        self::assertSame('America/New_York', $config->timezone);
        self::assertSame('fr', $config->locale);
    }

    #[Test]
    public function appliesDefaults(): void
    {
        $env = Environment::load();

        $config = AppConfig::fromArray([], $env);

        self::assertSame('Pulsar', $config->name);
        self::assertSame(EnvironmentMode::Local, $config->mode);
        self::assertSame('UTC', $config->timezone);
        self::assertSame('en', $config->locale);
    }

    #[Test]
    public function debugDefaultsByMode(): void
    {
        $env = Environment::load();

        // Local mode: debug defaults to true
        $config = AppConfig::fromArray(['env' => 'local'], $env);
        self::assertTrue($config->debug);

        // Production mode: debug defaults to false
        $config = AppConfig::fromArray(['env' => 'production'], $env);
        self::assertFalse($config->debug);

        // Staging mode: debug defaults to false
        $config = AppConfig::fromArray(['env' => 'staging'], $env);
        self::assertFalse($config->debug);
    }

    #[Test]
    public function envVarOverridesDebug(): void
    {
        putenv('APP_DEBUG=true');
        $env = Environment::load();

        // Even with production mode, env var forces debug on
        $config = AppConfig::fromArray([
            'env' => 'production',
            'debug' => false,
        ], $env);

        self::assertTrue($config->debug);
    }

    #[Test]
    public function envVarOverridesName(): void
    {
        putenv('APP_NAME=OverriddenApp');
        $env = Environment::load();

        $config = AppConfig::fromArray(['name' => 'OriginalApp'], $env);

        self::assertSame('OverriddenApp', $config->name);
    }

    #[Test]
    public function envVarOverridesMode(): void
    {
        putenv('APP_ENV=staging');
        $env = Environment::load();

        $config = AppConfig::fromArray(['env' => 'production'], $env);

        self::assertSame(EnvironmentMode::Staging, $config->mode);
    }

    #[Test]
    public function fileLevelDebugOverridesModeDefault(): void
    {
        $env = Environment::load();

        // Local mode normally defaults debug to true, but file says false
        $config = AppConfig::fromArray([
            'env' => 'local',
            'debug' => false,
        ], $env);

        self::assertFalse($config->debug);
    }
}
