<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;

use function getcwd;

use const DIRECTORY_SEPARATOR;
use const PHP_OS_FAMILY;

#[CoversClass(Driver::class)]
final class DriverSqlitePathTest extends TestCase
{
    #[Test]
    public function memoryDatabaseStaysAsIs(): void
    {
        // Arrange / Act
        $dsn = Driver::SQLite->buildDsn('', 0, ':memory:');

        // Assert
        self::assertSame('sqlite::memory:', $dsn);
    }

    #[Test]
    public function emptyStringStaysAsIs(): void
    {
        $dsn = Driver::SQLite->buildDsn('', 0, '');

        self::assertSame('sqlite:', $dsn);
    }

    #[Test]
    public function unixAbsolutePathStaysAsIs(): void
    {
        $dsn = Driver::SQLite->buildDsn('', 0, '/var/db/test.sqlite');

        self::assertSame('sqlite:/var/db/test.sqlite', $dsn);
    }

    #[Test]
    public function windowsAbsolutePathStaysAsIs(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows-only path test');
        }

        $dsn = Driver::SQLite->buildDsn('', 0, 'C:\\db\\test.sqlite');

        self::assertSame('sqlite:C:\\db\\test.sqlite', $dsn);
    }

    #[Test]
    public function relativePathGetsPrependedWithCwd(): void
    {
        // Arrange
        $cwd = getcwd();
        self::assertIsString($cwd, 'getcwd() must return a valid directory');

        // Act
        $dsn = Driver::SQLite->buildDsn('', 0, 'database/test.sqlite');

        // Assert
        $expected = 'sqlite:' . $cwd . DIRECTORY_SEPARATOR . 'database/test.sqlite';
        self::assertSame($expected, $dsn);
    }

    #[Test]
    public function relativePathWithSubdirGetsCwdPrepended(): void
    {
        $cwd = getcwd();
        self::assertIsString($cwd);

        $dsn = Driver::SQLite->buildDsn('', 0, 'storage/db/app.sqlite');

        $expected = 'sqlite:' . $cwd . DIRECTORY_SEPARATOR . 'storage/db/app.sqlite';
        self::assertSame($expected, $dsn);
    }

    #[Test]
    public function bareFilenameGetsCwdPrepended(): void
    {
        $cwd = getcwd();
        self::assertIsString($cwd);

        $dsn = Driver::SQLite->buildDsn('', 0, 'app.db');

        $expected = 'sqlite:' . $cwd . DIRECTORY_SEPARATOR . 'app.db';
        self::assertSame($expected, $dsn);
    }
}
