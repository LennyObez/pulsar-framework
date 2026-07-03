<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\Exception\ConfigException;

use const PHP_OS_FAMILY;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    private string $tempDir;

    /**
     * Per-test unique tempdir under the OS temp area. Cleanup is left
     * to the OS (sys_get_temp_dir is wiped by standard housekeeping)
     * so the test stays free of recursive-unlink patterns that trip
     * static-analysis path-traversal warnings.
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_env_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
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

    #[Test]
    public function parsesExportPrefix(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "export PULSAR_EXPORTED=exported_value\n");

        $env = Environment::load($envFile);

        self::assertSame('exported_value', $env->get('PULSAR_EXPORTED'));
    }

    #[Test]
    public function stripsInlineCommentsWithSpace(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "PULSAR_COMMENTED=value # this is a comment\n");

        $env = Environment::load($envFile);

        self::assertSame('value', $env->get('PULSAR_COMMENTED'));
    }

    #[Test]
    public function stripsInlineCommentsWithTab(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "PULSAR_TAB_COMMENT=value\t# tab comment\n");

        $env = Environment::load($envFile);

        self::assertSame('value', $env->get('PULSAR_TAB_COMMENT'));
    }

    #[Test]
    public function preservesHashInQuotedValues(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "PULSAR_QUOTED=\"value # not a comment\"\n");

        $env = Environment::load($envFile);

        self::assertSame('value # not a comment', $env->get('PULSAR_QUOTED'));
    }

    #[Test]
    public function preservesMidWordHash(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "PULSAR_HASH=value#notcomment\n");

        $env = Environment::load($envFile);

        self::assertSame('value#notcomment', $env->get('PULSAR_HASH'));
    }

    /**
     * F4.9: `loadFiltered()` uses a default prefix allowlist so that
     * adjacent-process secrets / Apache `SetEnv` / php-fpm `env[]`
     * cannot leak into Pulsar's view of the world. A non-allowlisted
     * variable disappears, while `PULSAR_*` and the literal allowlist
     * survive.
     */
    #[Test]
    public function loadFilteredAllowsPulsarPrefixAndDropsForeign(): void
    {
        putenv('PULSAR_FILTER_TEST=should-survive');
        putenv('UNRELATED_LEAK=should-disappear');

        try {
            $env = Environment::loadFiltered();

            self::assertSame('should-survive', $env->get('PULSAR_FILTER_TEST'));
            self::assertNull($env->get('UNRELATED_LEAK'));
        } finally {
            putenv('PULSAR_FILTER_TEST');
            putenv('UNRELATED_LEAK');
        }
    }

    #[Test]
    public function loadFilteredCustomAllowlistOverridesDefaults(): void
    {
        putenv('PULSAR_DEFAULT=in-default');
        putenv('CUSTOM_PREFIX_VAL=custom-allowed');

        try {
            $env = Environment::loadFiltered(
                envFilePath: null,
                prefixAllowlist: ['CUSTOM_PREFIX_'],
                literalAllowlist: [],
            );

            // PULSAR_ is no longer in the override allowlist
            self::assertNull($env->get('PULSAR_DEFAULT'));
            self::assertSame('custom-allowed', $env->get('CUSTOM_PREFIX_VAL'));
        } finally {
            putenv('PULSAR_DEFAULT');
            putenv('CUSTOM_PREFIX_VAL');
        }
    }

    #[Test]
    public function loadFilteredKeepsLiteralAllowlist(): void
    {
        // PATH is in the default literal allowlist
        $env = Environment::loadFiltered();

        self::assertNotNull($env->get('PATH'));
    }

    #[Test]
    public function lookupHonoursPlatformEnvNameCasing(): void
    {
        // Regression: Windows reports env names in the OS's own casing (e.g.
        // "Path"), so loadFiltered() must match its POSIX-cased allowlist and
        // resolve get() case-insensitively there; on POSIX names stay distinct.
        putenv('PULSAR_CASE_PROBE=on');

        try {
            $env = Environment::loadFiltered();

            self::assertSame('on', $env->get('PULSAR_CASE_PROBE'));

            if (PHP_OS_FAMILY === 'Windows') {
                // A differently-cased lookup resolves, and the case-insensitive
                // literal allowlist keeps the OS-cased "Path" reachable as PATH.
                self::assertSame('on', $env->get('pulsar_case_probe'));
                self::assertNotNull($env->get('PATH'));
                self::assertNotNull($env->get('Path'));
            } else {
                // POSIX: a differently-cased lookup misses (PATH !== path).
                self::assertNull($env->get('pulsar_case_probe'));
            }
        } finally {
            putenv('PULSAR_CASE_PROBE');
        }
    }
}
