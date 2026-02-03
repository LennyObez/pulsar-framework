<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;

#[CoversClass(Driver::class)]
final class DriverTest extends TestCase
{
    #[Test]
    public function mysqlBuildsDsnCorrectly(): void
    {
        $dsn = Driver::MySQL->buildDsn('localhost', 3306, 'testdb');

        self::assertSame('mysql:host=localhost;port=3306;dbname=testdb', $dsn);
    }

    #[Test]
    public function postgresqlBuildsDsnCorrectly(): void
    {
        $dsn = Driver::PostgreSQL->buildDsn('db.example.com', 5432, 'app_db');

        self::assertSame('pgsql:host=db.example.com;port=5432;dbname=app_db', $dsn);
    }

    #[Test]
    public function sqliteBuildsDsnCorrectly(): void
    {
        $dsn = Driver::SQLite->buildDsn('', 0, '/tmp/test.sqlite');

        self::assertSame('sqlite:/tmp/test.sqlite', $dsn);
    }

    #[Test]
    public function mysqlDefaultPortIs3306(): void
    {
        self::assertSame(3306, Driver::MySQL->defaultPort());
    }

    #[Test]
    public function postgresqlDefaultPortIs5432(): void
    {
        self::assertSame(5432, Driver::PostgreSQL->defaultPort());
    }

    #[Test]
    public function sqliteDefaultPortIsZero(): void
    {
        self::assertSame(0, Driver::SQLite->defaultPort());
    }

    #[Test]
    public function allDriversSupportSavepoints(): void
    {
        self::assertTrue(Driver::MySQL->supportsSavepoints());
        self::assertTrue(Driver::PostgreSQL->supportsSavepoints());
        self::assertTrue(Driver::SQLite->supportsSavepoints());
    }

    #[Test]
    public function driversHaveCorrectBackingValues(): void
    {
        self::assertSame('mysql', Driver::MySQL->value);
        self::assertSame('pgsql', Driver::PostgreSQL->value);
        self::assertSame('sqlite', Driver::SQLite->value);
    }

    #[Test]
    public function canCreateDriverFromStringValue(): void
    {
        self::assertSame(Driver::MySQL, Driver::from('mysql'));
        self::assertSame(Driver::PostgreSQL, Driver::from('pgsql'));
        self::assertSame(Driver::SQLite, Driver::from('sqlite'));
    }
}
