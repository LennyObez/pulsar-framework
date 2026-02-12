<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Internal\Compiler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\MySqlDialect;

final class MySqlDialectTest extends TestCase
{
    private MySqlDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new MySqlDialect();
    }

    #[Test]
    public function quoteIdentifierUsesBackticks(): void
    {
        self::assertSame('`users`', $this->dialect->quoteIdentifier('users'));
    }

    #[Test]
    public function compileLimitOffsetBothSet(): void
    {
        self::assertSame(' LIMIT 10 OFFSET 5', $this->dialect->compileLimitOffset(10, 5));
    }

    #[Test]
    public function compileLimitOffsetOnlyLimit(): void
    {
        self::assertSame(' LIMIT 10', $this->dialect->compileLimitOffset(10, null));
    }

    #[Test]
    public function compileLimitOffsetNone(): void
    {
        self::assertSame('', $this->dialect->compileLimitOffset(null, null));
    }

    #[Test]
    public function compileLimitOffsetZeroOffset(): void
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
        self::assertSame(' LOCK IN SHARE MODE', $this->dialect->compileLock(LockMode::ForShare));
    }

    #[Test]
    public function compileLockNone(): void
    {
        self::assertSame('', $this->dialect->compileLock(LockMode::None));
    }

    #[Test]
    public function currentTimestamp(): void
    {
        self::assertSame('NOW()', $this->dialect->currentTimestamp());
    }

    #[Test]
    public function compileBooleanLiteral(): void
    {
        self::assertSame('1', $this->dialect->compileBooleanLiteral(true));
        self::assertSame('0', $this->dialect->compileBooleanLiteral(false));
    }

    #[Test]
    public function supportsReturningIsFalse(): void
    {
        self::assertFalse($this->dialect->supportsReturning());
    }

    #[Test]
    public function compileUpsert(): void
    {
        $result = $this->dialect->compileUpsert(
            'INSERT INTO users (name) VALUES (:p0)',
            ['name'],
            ['name'],
        );

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $result);
        self::assertStringContainsString('`name` = VALUES(`name`)', $result);
    }

    #[Test]
    public function nameReturnsMysql(): void
    {
        self::assertSame('mysql', $this->dialect->name());
    }
}
