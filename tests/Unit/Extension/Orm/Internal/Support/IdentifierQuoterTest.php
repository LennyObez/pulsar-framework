<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Internal\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Internal\Compiler\MySqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\PostgreSqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\SqliteDialect;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

#[CoversClass(IdentifierQuoter::class)]
final class IdentifierQuoterTest extends TestCase
{
    #[Test]
    public function mysqlBacktickQuoting(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);

        self::assertSame('`users`', $quoter->quote('users'));
    }

    #[Test]
    public function mysqlQualifiedIdentifier(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);

        self::assertSame('`t0`.`name`', $quoter->quote('t0.name'));
    }

    #[Test]
    public function postgreSqlDoubleQuoting(): void
    {
        $quoter = new IdentifierQuoter(Driver::PostgreSQL);

        self::assertSame('"users"', $quoter->quote('users'));
    }

    #[Test]
    public function postgreSqlQualifiedIdentifier(): void
    {
        $quoter = new IdentifierQuoter(Driver::PostgreSQL);

        self::assertSame('"t0"."name"', $quoter->quote('t0.name'));
    }

    #[Test]
    public function sqliteDoubleQuoting(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);

        self::assertSame('"users"', $quoter->quote('users'));
    }

    #[Test]
    public function sqliteQualifiedIdentifier(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);

        self::assertSame('"t0"."name"', $quoter->quote('t0.name'));
    }

    #[Test]
    public function dialectForMySql(): void
    {
        $dialect = IdentifierQuoter::dialectFor(Driver::MySQL);

        self::assertInstanceOf(MySqlDialect::class, $dialect);
    }

    #[Test]
    public function dialectForPostgreSql(): void
    {
        $dialect = IdentifierQuoter::dialectFor(Driver::PostgreSQL);

        self::assertInstanceOf(PostgreSqlDialect::class, $dialect);
    }

    #[Test]
    public function dialectForSqlite(): void
    {
        $dialect = IdentifierQuoter::dialectFor(Driver::SQLite);

        self::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    #[Test]
    public function dialectMethodReturnsDialect(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);

        self::assertInstanceOf(MySqlDialect::class, $quoter->dialect());
    }
}
