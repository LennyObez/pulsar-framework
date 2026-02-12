<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Internal\Compiler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\PostgreSqlDialect;

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
    }

    #[Test]
    public function compileLockForShare(): void
    {
        self::assertSame(' FOR SHARE', $this->dialect->compileLock(LockMode::ForShare));
    }

    #[Test]
    public function compileBooleanLiteral(): void
    {
        self::assertSame('TRUE', $this->dialect->compileBooleanLiteral(true));
        self::assertSame('FALSE', $this->dialect->compileBooleanLiteral(false));
    }

    #[Test]
    public function supportsReturningIsTrue(): void
    {
        self::assertTrue($this->dialect->supportsReturning());
    }

    #[Test]
    public function compileUpsertUsesOnConflict(): void
    {
        $result = $this->dialect->compileUpsert(
            'INSERT INTO users (name) VALUES (:p0)',
            ['name'],
            ['name'],
        );

        self::assertStringContainsString('ON CONFLICT ("name")', $result);
        self::assertStringContainsString('"name" = EXCLUDED."name"', $result);
    }

    #[Test]
    public function nameReturnsPgsql(): void
    {
        self::assertSame('pgsql', $this->dialect->name());
    }
}
