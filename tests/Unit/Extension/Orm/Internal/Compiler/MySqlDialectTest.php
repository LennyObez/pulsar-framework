<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Internal\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\MySqlDialect;

#[CoversClass(MySqlDialect::class)]
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
        self::assertSame('`email_address`', $this->dialect->quoteIdentifier('email_address'));
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
        self::assertSame(' LOCK IN SHARE MODE', $this->dialect->compileLock(LockMode::ForShare));
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
        self::assertSame('1', $this->dialect->compileBooleanLiteral(true));
        self::assertSame('0', $this->dialect->compileBooleanLiteral(false));
    }

    #[Test]
    public function doesNotSupportReturning(): void
    {
        self::assertFalse($this->dialect->supportsReturning());
    }

    #[Test]
    public function nameReturnsMysql(): void
    {
        self::assertSame('mysql', $this->dialect->name());
    }

    #[Test]
    public function compileUpsertUsesOnDuplicateKey(): void
    {
        $sql = $this->dialect->compileUpsert(
            'INSERT INTO `users` (`id`, `name`, `email`) VALUES (:p0, :p1, :p2)',
            ['id'],
            ['name', 'email'],
        );

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        self::assertStringContainsString('`name` = VALUES(`name`)', $sql);
        self::assertStringContainsString('`email` = VALUES(`email`)', $sql);
    }

    #[Test]
    public function compileUpsertWithSingleUpdateColumn(): void
    {
        $sql = $this->dialect->compileUpsert(
            'INSERT INTO `users` (`id`, `status`) VALUES (:p0, :p1)',
            ['id'],
            ['status'],
        );

        self::assertStringContainsString('`status` = VALUES(`status`)', $sql);
        self::assertStringNotContainsString(', `status`', explode('ON DUPLICATE KEY UPDATE', $sql)[1] ?? '');
    }
}
