<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\SandboxConnection;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

#[CoversClass(SandboxConnection::class)]
final class SandboxConnectionTest extends TestCase
{
    #[Test]
    public function queryDelegatesToInnerConnection(): void
    {
        $expectedResult = new Result([new Row(['id' => 1])]);

        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn($expectedResult);

        $sandbox = new SandboxConnection($inner);
        $result = $sandbox->query('SELECT * FROM users');

        self::assertSame($expectedResult, $result);
    }

    #[Test]
    public function executeDelegatesToInnerConnection(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('execute')->willReturn(3);

        $sandbox = new SandboxConnection($inner);
        $affected = $sandbox->execute('DELETE FROM sessions WHERE expired = 1');

        self::assertSame(3, $affected);
    }

    #[Test]
    public function isActiveReturnsFalseBeforeBegin(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $sandbox = new SandboxConnection($inner);

        self::assertFalse($sandbox->isActive());
    }

    #[Test]
    public function rollbackWithoutBeginDoesNothing(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);

        $sandbox = new SandboxConnection($inner);
        $sandbox->rollback(); // Should not throw

        self::assertFalse($sandbox->isActive());
    }

    #[Test]
    public function transactionDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('transaction')->willReturn('callback-result');

        $sandbox = new SandboxConnection($inner);
        $result = $sandbox->transaction(static fn(): string => 'callback-result');

        self::assertSame('callback-result', $result);
    }

    #[Test]
    public function lastInsertIdDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('lastInsertId')->willReturn('42');

        $sandbox = new SandboxConnection($inner);

        self::assertSame('42', $sandbox->lastInsertId());
    }

    #[Test]
    public function driverDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('driver')->willReturn(Driver::PostgreSQL);

        $sandbox = new SandboxConnection($inner);

        self::assertSame(Driver::PostgreSQL, $sandbox->driver());
    }

    #[Test]
    public function nameDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('name')->willReturn('primary');

        $sandbox = new SandboxConnection($inner);

        self::assertSame('primary', $sandbox->name());
    }

    #[Test]
    public function inTransactionDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('inTransaction')->willReturn(true);

        $sandbox = new SandboxConnection($inner);

        self::assertTrue($sandbox->inTransaction());
    }

    #[Test]
    public function disconnectDelegatesToInner(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('disconnect');

        $sandbox = new SandboxConnection($inner);
        $sandbox->disconnect();
    }

    #[Test]
    public function queryPassesBindings(): void
    {
        $expectedResult = new Result([]);

        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('query')
            ->with('SELECT * FROM users WHERE id = :id', ['id' => 5])
            ->willReturn($expectedResult);

        $sandbox = new SandboxConnection($inner);
        $result = $sandbox->query('SELECT * FROM users WHERE id = :id', ['id' => 5]);

        self::assertSame($expectedResult, $result);
    }

    #[Test]
    public function executePassesBindings(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('execute')
            ->with('UPDATE users SET name = :name WHERE id = :id', ['name' => 'Alice', 'id' => 1])
            ->willReturn(1);

        $sandbox = new SandboxConnection($inner);
        $affected = $sandbox->execute('UPDATE users SET name = :name WHERE id = :id', ['name' => 'Alice', 'id' => 1]);

        self::assertSame(1, $affected);
    }

    #[Test]
    public function driverReturnsCorrectType(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('driver')->willReturn(Driver::SQLite);

        $sandbox = new SandboxConnection($inner);

        self::assertSame(Driver::SQLite, $sandbox->driver());
    }

    #[Test]
    public function inTransactionReturnsFalseWhenNotInTransaction(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('inTransaction')->willReturn(false);

        $sandbox = new SandboxConnection($inner);

        self::assertFalse($sandbox->inTransaction());
    }
}
