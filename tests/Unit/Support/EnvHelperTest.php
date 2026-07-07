<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;

use function bin2hex;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * Tests for env(), base_path(), storage_path(), resource_path(),
 * config_path(), and public_path() helper functions.
 */
#[CoversFunction('env')]
#[CoversFunction('base_path')]
#[CoversFunction('storage_path')]
#[CoversFunction('resource_path')]
#[CoversFunction('config_path')]
#[CoversFunction('public_path')]
final class EnvHelperTest extends TestCase
{
    protected function setUp(): void
    {
        // The getenv-path tests below require no active environment bound.
        Environment::resetActive();
    }

    protected function tearDown(): void
    {
        // Clean up env vars set during tests
        putenv('PULSAR_TEST_ENV_KEY');
        putenv('PULSAR_TEST_BOOL');
        putenv('PULSAR_BASE_PATH');
        Environment::resetActive();
    }

    #[Test]
    public function envReadsActiveEnvironmentValueNotInGetenv(): void
    {
        // A key present only in .env (loaded into the Environment, absent from
        // the OS process env) must resolve through env() once the Environment is
        // active — the core of ADR-0033.
        putenv('PULSAR_DOTENV_ONLY'); // ensure NOT in getenv
        $envFile = sys_get_temp_dir() . '/pulsar_env_helper_' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($envFile, "PULSAR_DOTENV_ONLY=from_dotenv\n");

        try {
            Environment::activate(Environment::load($envFile));

            self::assertSame('from_dotenv', env('PULSAR_DOTENV_ONLY'));
            self::assertFalse(getenv('PULSAR_DOTENV_ONLY')); // proves it is not in the OS env
        } finally {
            Environment::resetActive();
            unlink($envFile);
        }
    }

    #[Test]
    public function envCoercesValueFromActiveEnvironment(): void
    {
        $envFile = sys_get_temp_dir() . '/pulsar_env_helper_' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($envFile, "PULSAR_DOTENV_FLAG=true\n");

        try {
            Environment::activate(Environment::load($envFile));

            self::assertTrue(env('PULSAR_DOTENV_FLAG'));
        } finally {
            Environment::resetActive();
            unlink($envFile);
        }
    }

    #[Test]
    public function envReturnsDefaultWhenActiveEnvironmentLacksKey(): void
    {
        Environment::activate(Environment::load(null)); // OS env only, no .env

        try {
            self::assertSame('fallback', env('PULSAR_DEFINITELY_ABSENT_KEY_XYZ', 'fallback'));
        } finally {
            Environment::resetActive();
        }
    }

    #[Test]
    public function envReturnsDefaultWhenVariableNotSet(): void
    {
        putenv('PULSAR_TEST_ENV_KEY');

        self::assertNull(env('PULSAR_TEST_ENV_KEY'));
        self::assertSame('fallback', env('PULSAR_TEST_ENV_KEY', 'fallback'));
        self::assertSame(42, env('PULSAR_TEST_ENV_KEY', 42));
    }

    #[Test]
    public function envReturnsStringValueWhenSet(): void
    {
        putenv('PULSAR_TEST_ENV_KEY=hello');

        self::assertSame('hello', env('PULSAR_TEST_ENV_KEY'));
    }

    #[Test]
    #[DataProvider('booleanStringProvider')]
    public function envCoercesBooleanStrings(string $envValue, bool $expected): void
    {
        putenv('PULSAR_TEST_BOOL=' . $envValue);

        self::assertSame($expected, env('PULSAR_TEST_BOOL'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleanStringProvider(): iterable
    {
        yield 'true' => ['true', true];
        yield 'TRUE' => ['TRUE', true];
        yield '(true)' => ['(true)', true];
        yield 'false' => ['false', false];
        yield 'FALSE' => ['FALSE', false];
        yield '(false)' => ['(false)', false];
    }

    #[Test]
    public function envCoercesNullString(): void
    {
        putenv('PULSAR_TEST_ENV_KEY=null');
        self::assertNull(env('PULSAR_TEST_ENV_KEY'));

        putenv('PULSAR_TEST_ENV_KEY=(null)');
        self::assertNull(env('PULSAR_TEST_ENV_KEY'));
    }

    #[Test]
    public function envCoercesEmptyString(): void
    {
        putenv('PULSAR_TEST_ENV_KEY=empty');
        self::assertSame('', env('PULSAR_TEST_ENV_KEY'));

        putenv('PULSAR_TEST_ENV_KEY=(empty)');
        self::assertSame('', env('PULSAR_TEST_ENV_KEY'));
    }

    #[Test]
    public function envPreservesRegularStrings(): void
    {
        putenv('PULSAR_TEST_ENV_KEY=database.sqlite');
        self::assertSame('database.sqlite', env('PULSAR_TEST_ENV_KEY'));

        putenv('PULSAR_TEST_ENV_KEY=0');
        self::assertSame('0', env('PULSAR_TEST_ENV_KEY'));

        putenv('PULSAR_TEST_ENV_KEY=1');
        self::assertSame('1', env('PULSAR_TEST_ENV_KEY'));
    }

    #[Test]
    public function basePathReturnsCwdByDefault(): void
    {
        // base_path uses a static cache, so we test via the PULSAR_BASE_PATH env
        putenv('PULSAR_BASE_PATH');

        // The function has a static cache, so we can only test the concatenation behavior
        $result = base_path();
        self::assertNotSame('', $result);
        self::assertDirectoryExists($result);
    }

    #[Test]
    public function basePathAppendsPathSegment(): void
    {
        $base = base_path();
        $withSegment = base_path('config');

        self::assertSame($base . DIRECTORY_SEPARATOR . 'config', $withSegment);
    }

    #[Test]
    public function basePathReturnsBaseAloneWithEmptyString(): void
    {
        $base = base_path('');
        $baseNoArg = base_path();

        self::assertSame($baseNoArg, $base);
    }

    // --- storage_path() ---

    #[Test]
    public function storagePathReturnsStorageDirWithNoArgument(): void
    {
        $expected = base_path('storage');

        self::assertSame($expected, storage_path());
    }

    #[Test]
    public function storagePathReturnsStorageDirWithEmptyString(): void
    {
        $expected = base_path('storage');

        self::assertSame($expected, storage_path(''));
    }

    #[Test]
    #[DataProvider('subPathProvider')]
    public function storagePathAppendsSubPath(string $subPath): void
    {
        $expected = base_path('storage' . DIRECTORY_SEPARATOR . $subPath);

        self::assertSame($expected, storage_path($subPath));
    }

    // --- resource_path() ---

    #[Test]
    public function resourcePathReturnsResourcesDirWithNoArgument(): void
    {
        $expected = base_path('resources');

        self::assertSame($expected, resource_path());
    }

    #[Test]
    public function resourcePathReturnsResourcesDirWithEmptyString(): void
    {
        $expected = base_path('resources');

        self::assertSame($expected, resource_path(''));
    }

    #[Test]
    #[DataProvider('subPathProvider')]
    public function resourcePathAppendsSubPath(string $subPath): void
    {
        $expected = base_path('resources' . DIRECTORY_SEPARATOR . $subPath);

        self::assertSame($expected, resource_path($subPath));
    }

    // --- config_path() ---

    #[Test]
    public function configPathReturnsConfigDirWithNoArgument(): void
    {
        $expected = base_path('config');

        self::assertSame($expected, config_path());
    }

    #[Test]
    public function configPathReturnsConfigDirWithEmptyString(): void
    {
        $expected = base_path('config');

        self::assertSame($expected, config_path(''));
    }

    #[Test]
    #[DataProvider('subPathProvider')]
    public function configPathAppendsSubPath(string $subPath): void
    {
        $expected = base_path('config' . DIRECTORY_SEPARATOR . $subPath);

        self::assertSame($expected, config_path($subPath));
    }

    // --- public_path() ---

    #[Test]
    public function publicPathReturnsPublicDirWithNoArgument(): void
    {
        $expected = base_path('public');

        self::assertSame($expected, public_path());
    }

    #[Test]
    public function publicPathReturnsPublicDirWithEmptyString(): void
    {
        $expected = base_path('public');

        self::assertSame($expected, public_path(''));
    }

    #[Test]
    #[DataProvider('subPathProvider')]
    public function publicPathAppendsSubPath(string $subPath): void
    {
        $expected = base_path('public' . DIRECTORY_SEPARATOR . $subPath);

        self::assertSame($expected, public_path($subPath));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function subPathProvider(): iterable
    {
        yield 'simple file' => ['app.css'];
        yield 'nested path' => ['cache' . DIRECTORY_SEPARATOR . 'views'];
        yield 'deep nesting' => ['logs' . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '03'];
    }
}
