<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Internal\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Internal\Compiler\MySqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\PostgreSqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\SqliteDialect;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

final class IdentifierQuoterTest extends TestCase
{
    #[Test]
    public function quoteMysqlUsesBackticks(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);

        self::assertSame('`users`', $quoter->quote('users'));
    }

    #[Test]
    public function quotePostgreSqlUsesDoubleQuotes(): void
    {
        $quoter = new IdentifierQuoter(Driver::PostgreSQL);

        self::assertSame('"users"', $quoter->quote('users'));
    }

    #[Test]
    public function quoteSqliteUsesDoubleQuotes(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);

        self::assertSame('"users"', $quoter->quote('users'));
    }

    #[Test]
    public function quoteQualifiedIdentifier(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);

        self::assertSame('`t0`.`name`', $quoter->quote('t0.name'));
    }

    #[Test]
    public function dialectReturnsCorrectInstance(): void
    {
        self::assertInstanceOf(MySqlDialect::class, new IdentifierQuoter(Driver::MySQL)->dialect());
        self::assertInstanceOf(PostgreSqlDialect::class, new IdentifierQuoter(Driver::PostgreSQL)->dialect());
        self::assertInstanceOf(SqliteDialect::class, new IdentifierQuoter(Driver::SQLite)->dialect());
    }

    #[Test]
    public function dialectForStaticMethod(): void
    {
        self::assertInstanceOf(MySqlDialect::class, IdentifierQuoter::dialectFor(Driver::MySQL));
        self::assertInstanceOf(PostgreSqlDialect::class, IdentifierQuoter::dialectFor(Driver::PostgreSQL));
        self::assertInstanceOf(SqliteDialect::class, IdentifierQuoter::dialectFor(Driver::SQLite));
    }
}
