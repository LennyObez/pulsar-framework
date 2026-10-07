<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Internal\Compiler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\SqliteDialect;

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
    }

    #[Test]
    public function compileLockAlwaysReturnsEmpty(): void
    {
        self::assertSame('', $this->dialect->compileLock(LockMode::ForUpdate));
        self::assertSame('', $this->dialect->compileLock(LockMode::ForShare));
        self::assertSame('', $this->dialect->compileLock(LockMode::None));
    }

    #[Test]
    public function currentTimestamp(): void
    {
        self::assertSame("datetime('now')", $this->dialect->currentTimestamp());
    }

    #[Test]
    public function supportsReturningIsTrue(): void
    {
        self::assertTrue($this->dialect->supportsReturning());
    }

    #[Test]
    public function compileUpsertUsesOnConflictWithLowerExcluded(): void
    {
        $result = $this->dialect->compileUpsert(
            'INSERT INTO t (a) VALUES (:p0)',
            ['a'],
            ['a'],
        );

        self::assertStringContainsString('ON CONFLICT ("a")', $result);
        self::assertStringContainsString('"a" = excluded."a"', $result);
    }

    #[Test]
    public function nameReturnsSqlite(): void
    {
        self::assertSame('sqlite', $this->dialect->name());
    }
}
