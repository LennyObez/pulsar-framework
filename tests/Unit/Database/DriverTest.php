<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\InvalidDsnComponentException;

#[CoversNothing]
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

    #[Test]
    public function mysqlBuildsDsnWithCharset(): void
    {
        $dsn = Driver::MySQL->buildDsn('localhost', 3306, 'testdb', 'utf8mb4');

        self::assertSame('mysql:host=localhost;port=3306;dbname=testdb;charset=utf8mb4', $dsn);
    }

    #[Test]
    public function mysqlBuildsDsnWithoutCharsetWhenNull(): void
    {
        $dsn = Driver::MySQL->buildDsn('localhost', 3306, 'testdb', null);

        self::assertSame('mysql:host=localhost;port=3306;dbname=testdb', $dsn);
    }

    #[Test]
    public function postgresqlIgnoresCharsetParameter(): void
    {
        $dsn = Driver::PostgreSQL->buildDsn('localhost', 5432, 'testdb', 'utf8');

        self::assertSame('pgsql:host=localhost;port=5432;dbname=testdb', $dsn);
    }

    #[Test]
    public function sqliteIgnoresCharsetParameter(): void
    {
        $dsn = Driver::SQLite->buildDsn('', 0, '/tmp/test.sqlite', 'utf8');

        self::assertSame('sqlite:/tmp/test.sqlite', $dsn);
    }

    #[Test]
    public function buildDsnRejectsSemicolonInHost(): void
    {
        $this->expectException(InvalidDsnComponentException::class);
        $this->expectExceptionMessageMatches('/host/');

        Driver::MySQL->buildDsn('attacker.example.com;dbname=evil', 3306, 'app', 'utf8mb4');
    }

    #[Test]
    public function buildDsnRejectsEqualsInHost(): void
    {
        $this->expectException(InvalidDsnComponentException::class);

        Driver::PostgreSQL->buildDsn('host=evil', 5432, 'app');
    }

    #[Test]
    public function buildDsnRejectsSemicolonInDatabase(): void
    {
        $this->expectException(InvalidDsnComponentException::class);
        $this->expectExceptionMessageMatches('/database/');

        Driver::MySQL->buildDsn('localhost', 3306, 'app;charset=evil', 'utf8mb4');
    }

    #[Test]
    public function buildDsnRejectsNulInDatabase(): void
    {
        $this->expectException(InvalidDsnComponentException::class);

        Driver::MySQL->buildDsn('localhost', 3306, "app\x00malicious", null);
    }

    #[Test]
    public function buildDsnRejectsCrlfInCharset(): void
    {
        $this->expectException(InvalidDsnComponentException::class);

        Driver::MySQL->buildDsn('localhost', 3306, 'app', "utf8mb4\r\nunix_socket=/tmp/evil");
    }

    #[Test]
    public function buildDsnAllowsColonInSqlitePath(): void
    {
        // SQLite paths can legitimately contain `:` (Windows drive letters
        // and the `:memory:` sentinel). Only the structural DSN delimiters
        // are forbidden.
        $dsn = Driver::SQLite->buildDsn('', 0, ':memory:', null);

        self::assertSame('sqlite::memory:', $dsn);
    }

    /**
     * The DSN is the only place libpq reads sslmode from. Left in the PDO options array
     * it is discarded without a word, and the connection comes up in plaintext.
     */
    #[Test]
    public function postgresqlCarriesSslModeInTheDsn(): void
    {
        $dsn = Driver::PostgreSQL->buildDsn('db.example.com', 5432, 'app_db', null, 'verify-full');

        self::assertSame('pgsql:host=db.example.com;port=5432;dbname=app_db;sslmode=verify-full', $dsn);
    }

    #[Test]
    public function postgresqlOmitsSslModeWhenNoneIsAsked(): void
    {
        $dsn = Driver::PostgreSQL->buildDsn('db.example.com', 5432, 'app_db');

        self::assertSame('pgsql:host=db.example.com;port=5432;dbname=app_db', $dsn);
    }

    /**
     * A typo must not degrade to plaintext. libpq would reject `requre` only at connect
     * time, if it reached libpq at all, so it is refused here where the message is read.
     */
    #[Test]
    public function buildDsnRejectsAnUnknownSslMode(): void
    {
        $this->expectException(InvalidDsnComponentException::class);
        $this->expectExceptionMessageIsOrContains('unrecognised value');

        Driver::PostgreSQL->buildDsn('db', 5432, 'app', null, 'requre');
    }

    #[Test]
    public function buildDsnRejectsSslModeForMysqlWhereItIsNotADsnParameter(): void
    {
        $this->expectException(InvalidDsnComponentException::class);

        Driver::MySQL->buildDsn('localhost', 3306, 'app', 'utf8mb4', 'require');
    }

    #[Test]
    public function buildDsnRejectsSslModeForSqliteWhichHasNoTransport(): void
    {
        $this->expectException(InvalidDsnComponentException::class);

        Driver::SQLite->buildDsn('', 0, '/tmp/test.sqlite', null, 'require');
    }
}
