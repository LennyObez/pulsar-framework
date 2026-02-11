<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Internal\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\MySqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\PostgreSqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\SqliteDialect;

#[CoversClass(MySqlDialect::class)]
#[CoversClass(PostgreSqlDialect::class)]
#[CoversClass(SqliteDialect::class)]
final class DialectTest extends TestCase
{
    // --- MySQL ---

    #[Test]
    public function mysqlQuoteIdentifier(): void
    {
        $dialect = new MySqlDialect();
        self::assertSame('`users`', $dialect->quoteIdentifier('users'));
    }

    #[Test]
    public function mysqlLimitOffset(): void
    {
        $dialect = new MySqlDialect();
        self::assertSame(' LIMIT 10', $dialect->compileLimitOffset(10, null));
        self::assertSame(' LIMIT 10 OFFSET 5', $dialect->compileLimitOffset(10, 5));
        self::assertSame('', $dialect->compileLimitOffset(null, null));
        self::assertSame('', $dialect->compileLimitOffset(null, 0));
    }

    #[Test]
    public function mysqlLockModes(): void
    {
        $dialect = new MySqlDialect();
        self::assertSame('', $dialect->compileLock(LockMode::None));
        self::assertSame(' FOR UPDATE', $dialect->compileLock(LockMode::ForUpdate));
        self::assertSame(' LOCK IN SHARE MODE', $dialect->compileLock(LockMode::ForShare));
    }

    #[Test]
    public function mysqlCurrentTimestamp(): void
    {
        $dialect = new MySqlDialect();
        self::assertSame('NOW()', $dialect->currentTimestamp());
    }

    #[Test]
    public function mysqlBooleanLiteral(): void
    {
        $dialect = new MySqlDialect();
        self::assertSame('1', $dialect->compileBooleanLiteral(true));
        self::assertSame('0', $dialect->compileBooleanLiteral(false));
    }

    #[Test]
    public function mysqlDoesNotSupportReturning(): void
    {
        $dialect = new MySqlDialect();
        self::assertFalse($dialect->supportsReturning());
    }

    #[Test]
    public function mysqlName(): void
    {
        $dialect = new MySqlDialect();
        self::assertSame('mysql', $dialect->name());
    }

    #[Test]
    public function mysqlUpsert(): void
    {
        $dialect = new MySqlDialect();
        $result = $dialect->compileUpsert('INSERT INTO `users` (`id`, `name`) VALUES (:p0, :p1)', ['id'], ['name']);

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $result);
        self::assertStringContainsString('`name` = VALUES(`name`)', $result);
    }

    // --- PostgreSQL ---

    #[Test]
    public function pgsqlQuoteIdentifier(): void
    {
        $dialect = new PostgreSqlDialect();
        self::assertSame('"users"', $dialect->quoteIdentifier('users'));
    }

    #[Test]
    public function pgsqlLimitOffset(): void
    {
        $dialect = new PostgreSqlDialect();
        self::assertSame(' LIMIT 10', $dialect->compileLimitOffset(10, null));
        self::assertSame(' LIMIT 10 OFFSET 5', $dialect->compileLimitOffset(10, 5));
        self::assertSame('', $dialect->compileLimitOffset(null, null));
    }

    #[Test]
    public function pgsqlLockModes(): void
    {
        $dialect = new PostgreSqlDialect();
        self::assertSame('', $dialect->compileLock(LockMode::None));
        self::assertSame(' FOR UPDATE', $dialect->compileLock(LockMode::ForUpdate));
        self::assertSame(' FOR SHARE', $dialect->compileLock(LockMode::ForShare));
    }

    #[Test]
    public function pgsqlBooleanLiteral(): void
    {
        $dialect = new PostgreSqlDialect();
        self::assertSame('TRUE', $dialect->compileBooleanLiteral(true));
        self::assertSame('FALSE', $dialect->compileBooleanLiteral(false));
    }

    #[Test]
    public function pgsqlSupportsReturning(): void
    {
        $dialect = new PostgreSqlDialect();
        self::assertTrue($dialect->supportsReturning());
    }

    #[Test]
    public function pgsqlName(): void
    {
        $dialect = new PostgreSqlDialect();
        self::assertSame('pgsql', $dialect->name());
    }

    #[Test]
    public function pgsqlUpsert(): void
    {
        $dialect = new PostgreSqlDialect();
        $result = $dialect->compileUpsert('INSERT INTO "users" ("id", "name") VALUES (:p0, :p1)', ['id'], ['name']);

        self::assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET', $result);
        self::assertStringContainsString('"name" = EXCLUDED."name"', $result);
    }

    // --- SQLite ---

    #[Test]
    public function sqliteQuoteIdentifier(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame('"users"', $dialect->quoteIdentifier('users'));
    }

    #[Test]
    public function sqliteLimitOffset(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame(' LIMIT 10', $dialect->compileLimitOffset(10, null));
        self::assertSame(' LIMIT 10 OFFSET 5', $dialect->compileLimitOffset(10, 5));
        self::assertSame('', $dialect->compileLimitOffset(null, null));
    }

    #[Test]
    public function sqliteLockModeIsAlwaysEmpty(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame('', $dialect->compileLock(LockMode::None));
        self::assertSame('', $dialect->compileLock(LockMode::ForUpdate));
        self::assertSame('', $dialect->compileLock(LockMode::ForShare));
    }

    #[Test]
    public function sqliteCurrentTimestamp(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame("datetime('now')", $dialect->currentTimestamp());
    }

    #[Test]
    public function sqliteBooleanLiteral(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame('1', $dialect->compileBooleanLiteral(true));
        self::assertSame('0', $dialect->compileBooleanLiteral(false));
    }

    #[Test]
    public function sqliteSupportsReturning(): void
    {
        $dialect = new SqliteDialect();
        self::assertTrue($dialect->supportsReturning());
    }

    #[Test]
    public function sqliteName(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame('sqlite', $dialect->name());
    }

    #[Test]
    public function sqliteUpsert(): void
    {
        $dialect = new SqliteDialect();
        $result = $dialect->compileUpsert('INSERT INTO "users" ("id", "name") VALUES (:p0, :p1)', ['id'], ['name']);

        self::assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET', $result);
        self::assertStringContainsString('"name" = excluded."name"', $result);
    }
}
