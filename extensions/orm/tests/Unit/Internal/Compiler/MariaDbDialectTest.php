<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Internal\Compiler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\MariaDbDialect;

final class MariaDbDialectTest extends TestCase
{
    private MariaDbDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new MariaDbDialect();
    }

    #[Test]
    public function quoteIdentifierUsesBackticks(): void
    {
        self::assertSame('`users`', $this->dialect->quoteIdentifier('users'));
    }

    #[Test]
    public function compileLockForShareUsesShareMode(): void
    {
        self::assertSame(' LOCK IN SHARE MODE', $this->dialect->compileLock(LockMode::ForShare));
    }

    #[Test]
    public function supportsReturningIsTrue(): void
    {
        self::assertTrue($this->dialect->supportsReturning());
    }

    #[Test]
    public function nameReturnsMariadb(): void
    {
        self::assertSame('mariadb', $this->dialect->name());
    }

    #[Test]
    public function compileUpsertUsesOnDuplicateKey(): void
    {
        $result = $this->dialect->compileUpsert(
            'INSERT INTO t (x) VALUES (:p0)',
            ['x'],
            ['x'],
        );

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $result);
    }
}
