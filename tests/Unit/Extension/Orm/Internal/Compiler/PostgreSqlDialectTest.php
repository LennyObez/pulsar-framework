<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Internal\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\PostgreSqlDialect;

#[CoversClass(PostgreSqlDialect::class)]
final class PostgreSqlDialectTest extends TestCase
{
    private PostgreSqlDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new PostgreSqlDialect();
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
    public function compileLockForUpdate(): void
    {
        self::assertSame(' FOR UPDATE', $this->dialect->compileLock(LockMode::ForUpdate));
    }

    #[Test]
    public function compileLockForShare(): void
    {
        self::assertSame(' FOR SHARE', $this->dialect->compileLock(LockMode::ForShare));
    }

    #[Test]
    public function compileLockNone(): void
    {
        self::assertSame('', $this->dialect->compileLock(LockMode::None));
    }

    #[Test]
    public function currentTimestampReturnsNow(): void
    {
        self::assertSame('NOW()', $this->dialect->currentTimestamp());
    }

    #[Test]
    public function compileBooleanLiterals(): void
    {
        self::assertSame('TRUE', $this->dialect->compileBooleanLiteral(true));
        self::assertSame('FALSE', $this->dialect->compileBooleanLiteral(false));
    }

    #[Test]
    public function supportsReturning(): void
    {
        self::assertTrue($this->dialect->supportsReturning());
    }

    #[Test]
    public function nameReturnsPgsql(): void
    {
        self::assertSame('pgsql', $this->dialect->name());
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
        self::assertStringContainsString('"name" = EXCLUDED."name"', $sql);
        self::assertStringContainsString('"email" = EXCLUDED."email"', $sql);
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
        self::assertStringContainsString('"label" = EXCLUDED."label"', $sql);
    }
}
