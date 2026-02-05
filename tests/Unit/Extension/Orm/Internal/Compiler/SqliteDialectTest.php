<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Internal\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\SqliteDialect;

#[CoversClass(SqliteDialect::class)]
final class SqliteDialectTest extends TestCase
{
    private SqliteDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SqliteDialect();
    }

    #[Test]
    public function quoteIdentifierUsesDoubleQuotes(): void
    {
        self::assertSame('"users"', $this->dialect->quoteIdentifier('users'));
        self::assertSame('"email_address"', $this->dialect->quoteIdentifier('email_address'));
    }

    #[Test]
    public function compileLimitOffsetWithBothValues(): void
    {
        self::assertSame(' LIMIT 10 OFFSET 5', $this->dialect->compileLimitOffset(10, 5));
    }

    #[Test]
    public function compileLimitOffsetWithLimitOnly(): void
    {
        self::assertSame(' LIMIT 25', $this->dialect->compileLimitOffset(25, null));
    }

    #[Test]
    public function compileLimitOffsetReturnsEmptyForNulls(): void
    {
        self::assertSame('', $this->dialect->compileLimitOffset(null, null));
    }

    #[Test]
    public function compileLimitOffsetIgnoresZeroOffset(): void
    {
        self::assertSame(' LIMIT 10', $this->dialect->compileLimitOffset(10, 0));
    }

    #[Test]
    public function compileLockAlwaysReturnsEmpty(): void
    {
        self::assertSame('', $this->dialect->compileLock(LockMode::None));
        self::assertSame('', $this->dialect->compileLock(LockMode::ForUpdate));
        self::assertSame('', $this->dialect->compileLock(LockMode::ForShare));
    }

    #[Test]
    public function currentTimestampReturnsSqliteExpression(): void
    {
        self::assertSame("datetime('now')", $this->dialect->currentTimestamp());
    }

    #[Test]
    public function compileBooleanLiterals(): void
    {
        self::assertSame('1', $this->dialect->compileBooleanLiteral(true));
        self::assertSame('0', $this->dialect->compileBooleanLiteral(false));
    }

    #[Test]
    public function supportsReturning(): void
    {
        self::assertTrue($this->dialect->supportsReturning());
    }

    #[Test]
    public function nameReturnsSqlite(): void
    {
        self::assertSame('sqlite', $this->dialect->name());
    }

    #[Test]
    public function compileUpsertUsesOnConflictDoUpdate(): void
    {
        $sql = $this->dialect->compileUpsert(
            'INSERT INTO "users" ("id", "name", "email") VALUES (:p0, :p1, :p2)',
            ['id'],
            ['name', 'email'],
        );

        self::assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET', $sql);
        self::assertStringContainsString('"name" = excluded."name"', $sql);
        self::assertStringContainsString('"email" = excluded."email"', $sql);
    }

    #[Test]
    public function compileUpsertWithMultipleConflictColumns(): void
    {
        $sql = $this->dialect->compileUpsert(
            'INSERT INTO "tags" ("type", "slug", "label") VALUES (:p0, :p1, :p2)',
            ['type', 'slug'],
            ['label'],
        );

        self::assertStringContainsString('ON CONFLICT ("type", "slug") DO UPDATE SET', $sql);
        self::assertStringContainsString('"label" = excluded."label"', $sql);
    }

    #[Test]
    public function compileUpsertWithMultipleUpdateColumns(): void
    {
        $sql = $this->dialect->compileUpsert(
            'INSERT INTO "users" ("id", "name", "email", "status") VALUES (:p0, :p1, :p2, :p3)',
            ['id'],
            ['name', 'email', 'status'],
        );

        self::assertStringContainsString('"name" = excluded."name"', $sql);
        self::assertStringContainsString('"email" = excluded."email"', $sql);
        self::assertStringContainsString('"status" = excluded."status"', $sql);
    }

    #[Test]
    public function compileLimitOffsetWithOffsetOnly(): void
    {
        $result = $this->dialect->compileLimitOffset(null, 10);
        self::assertSame(' OFFSET 10', $result);
    }

    #[Test]
    public function compileLimitOffsetWithLargeValues(): void
    {
        $result = $this->dialect->compileLimitOffset(1000000, 5000000);
        self::assertSame(' LIMIT 1000000 OFFSET 5000000', $result);
    }
}
