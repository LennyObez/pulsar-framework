<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\Exception\ConfigException;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_env_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        // glob doesn't match dotfiles on Windows; use scandir
        if (is_dir($this->tempDir)) {
            $items = scandir($this->tempDir);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    $path = $this->tempDir . DIRECTORY_SEPARATOR . $item;
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function readsOsEnvironmentVariables(): void
    {
        $env = Environment::load();

        // PATH is virtually always set in OS env
        self::assertNotNull($env->get('PATH', null) ?? $env->get('Path', null));
    }

    #[Test]
    public function returnsDefaultForMissingVariable(): void
    {
        $env = Environment::load();

        self::assertNull($env->get('PULSAR_TEST_NONEXISTENT_VAR'));
        self::assertSame('fallback', $env->get('PULSAR_TEST_NONEXISTENT_VAR', 'fallback'));
    }

    #[Test]
    public function requireThrowsForMissingVariable(): void
    {
        $env = Environment::load();

        $this->expectException(ConfigException::class);
        $env->require('PULSAR_TEST_NONEXISTENT_VAR');
    }

    #[Test]
    public function parsesEnvFile(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "PULSAR_TEST_KEY=hello\nPULSAR_TEST_NUM=42\n");

        $env = Environment::load($envFile);

        self::assertSame('hello', $env->get('PULSAR_TEST_KEY'));
        self::assertSame('42', $env->get('PULSAR_TEST_NUM'));
    }

    #[Test]
    public function osVariablesOverrideFileValues(): void
    {
        // Set an OS env var
        putenv('PULSAR_TEST_OVERRIDE=from_os');

        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "PULSAR_TEST_OVERRIDE=from_file\n");

        $env = Environment::load($envFile);

        self::assertSame('from_os', $env->get('PULSAR_TEST_OVERRIDE'));

        putenv('PULSAR_TEST_OVERRIDE'); // Clean up
    }

    #[Test]
    public function ignoresCommentsAndBlankLines(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "# This is a comment\n\nPULSAR_VALID=yes\n\n# Another comment\n");

        $env = Environment::load($envFile);

        self::assertSame('yes', $env->get('PULSAR_VALID'));
    }

    #[Test]
    public function stripsQuotesFromValues(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "PULSAR_DOUBLE=\"double quoted\"\nPULSAR_SINGLE='single quoted'\n");

        $env = Environment::load($envFile);

        self::assertSame('double quoted', $env->get('PULSAR_DOUBLE'));
        self::assertSame('single quoted', $env->get('PULSAR_SINGLE'));
    }

    #[Test]
    public function resolvesModeFromAppEnv(): void
    {
        putenv('APP_ENV=production');

        $env = Environment::load();
        self::assertSame(EnvironmentMode::Production, $env->resolveMode());

        putenv('APP_ENV=staging');
        $env = Environment::load();
        self::assertSame(EnvironmentMode::Staging, $env->resolveMode());

        putenv('APP_ENV'); // Clean up
    }

    #[Test]
    public function resolvesLocalModeAsDefault(): void
    {
        // Ensure APP_ENV is unset
        putenv('APP_ENV');

        $env = Environment::load();

        self::assertSame(EnvironmentMode::Local, $env->resolveMode());
    }

    #[Test]
    public function hasChecksVariableExistence(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "PULSAR_EXISTS=1\n");

        $env = Environment::load($envFile);

        self::assertTrue($env->has('PULSAR_EXISTS'));
        self::assertFalse($env->has('PULSAR_NOT_EXISTS_ABC'));
    }

    #[Test]
    public function handlesNonexistentEnvFile(): void
    {
        $env = Environment::load('/nonexistent/path/.env');

        // Should not throw; file is optional
        self::assertInstanceOf(Environment::class, $env);
    }
}
