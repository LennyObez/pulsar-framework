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

use function substr;
use function substr_count;

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
    public function quoteEscapesEmbeddedBacktickToPreventMysqlBreakout(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);

        // A malicious "column" carrying a backtick must not be able to
        // close the identifier and inject raw SQL. The delimiter is
        // doubled, keeping the whole payload inside one quoted token.
        $malicious = 'id`,(SELECT password FROM users))-- ';

        $quoted = $quoter->quote($malicious);

        self::assertSame('`id``,(SELECT password FROM users))-- `', $quoted);
        // Proof of containment: every backtick is doubled, so there is no
        // lone delimiter that could terminate the identifier early.
        self::assertSame(0, substr_count(substr($quoted, 1, -1), '`') % 2);
    }

    #[Test]
    public function quoteEscapesEmbeddedDoubleQuoteToPreventPostgresBreakout(): void
    {
        $quoter = new IdentifierQuoter(Driver::PostgreSQL);

        $malicious = 'id",(SELECT password FROM users))-- ';

        self::assertSame(
            '"id"",(SELECT password FROM users))-- "',
            $quoter->quote($malicious),
        );
    }

    #[Test]
    public function quoteEscapesEmbeddedDoubleQuoteToPreventSqliteBreakout(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);

        self::assertSame('"a""b"', $quoter->quote('a"b'));
    }

    #[Test]
    public function quoteStripsNulBytesFromIdentifier(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);

        self::assertSame('`idextra`', $quoter->quote("id\0extra"));
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
