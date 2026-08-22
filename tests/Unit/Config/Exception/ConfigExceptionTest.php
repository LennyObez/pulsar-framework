<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
use RuntimeException;

#[CoversClass(ConfigException::class)]
final class ConfigExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = ConfigException::fileNotFound('/etc/app.php');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function fileNotFoundIncludesPath(): void
    {
        $exception = ConfigException::fileNotFound('/config/database.php');

        self::assertSame(
            'Configuration file not found: "/config/database.php"',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function invalidValueIncludesPathAndReason(): void
    {
        $exception = ConfigException::invalidValue('app.php', 'must return array');

        self::assertSame(
            'Invalid configuration value in "app.php": must return array',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function missingRequiredIncludesKeyAndContext(): void
    {
        $exception = ConfigException::missingRequired('database.host', 'DatabaseConfig');

        self::assertSame(
            'Missing required configuration key "database.host" in DatabaseConfig',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function fileNotFoundWithEmptyPath(): void
    {
        $exception = ConfigException::fileNotFound('');

        self::assertStringContainsString('""', $exception->getMessage());
    }

    #[Test]
    public function invalidValueWithSpecialCharacters(): void
    {
        $exception = ConfigException::invalidValue('mail.php', 'port must be int, got "abc"');

        self::assertStringContainsString('port must be int', $exception->getMessage());
    }
}
