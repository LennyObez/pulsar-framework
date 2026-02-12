<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Pool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Pool\ConnectionPoolInterface;
use Pulsar\Database\Pool\PooledConnection;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;

#[CoversClass(PooledConnection::class)]
final class PooledConnectionTest extends TestCase
{
    #[Test]
    public function delegatesQueryToWrappedConnection(): void
    {
        $wrapped = $this->createMock(ConnectionInterface::class);
        $wrapped->expects(self::once())
            ->method('query')
            ->with('SELECT 1', []);

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        $pooled->query('SELECT 1');
    }

    #[Test]
    public function delegatesExecuteToWrappedConnection(): void
    {
        $wrapped = $this->createMock(ConnectionInterface::class);
        $wrapped->expects(self::once())
            ->method('execute')
            ->with('DELETE FROM users', [])
            ->willReturn(5);

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        self::assertSame(5, $pooled->execute('DELETE FROM users'));
    }

    #[Test]
    public function delegatesPrepareToWrappedConnection(): void
    {
        $wrapped = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        $stmt = $pooled->prepare('SELECT 1');
        self::assertInstanceOf(Statement::class, $stmt);
    }

    #[Test]
    public function delegatesBeginTransactionToWrappedConnection(): void
    {
        $wrapped = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        $txn = $pooled->beginTransaction();
        self::assertInstanceOf(Transaction::class, $txn);
        $txn->commit();
    }

    #[Test]
    public function delegatesTransactionToWrappedConnection(): void
    {
        $wrapped = $this->createMock(ConnectionInterface::class);
        $wrapped->expects(self::once())
            ->method('transaction')
            ->willReturn('result');

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        self::assertSame('result', $pooled->transaction(static fn() => 'result'));
    }

    #[Test]
    public function delegatesLastInsertIdToWrappedConnection(): void
    {
        $wrapped = $this->createMock(ConnectionInterface::class);
        $wrapped->expects(self::once())
            ->method('lastInsertId')
            ->willReturn('42');

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        self::assertSame('42', $pooled->lastInsertId());
    }

    #[Test]
    public function delegatesDriverToWrappedConnection(): void
    {
        $wrapped = $this->createMock(ConnectionInterface::class);
        $wrapped->expects(self::once())
            ->method('driver')
            ->willReturn(Driver::PostgreSQL);

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        self::assertSame(Driver::PostgreSQL, $pooled->driver());
    }

    #[Test]
    public function delegatesNameToWrappedConnection(): void
    {
        $wrapped = $this->createMock(ConnectionInterface::class);
        $wrapped->expects(self::once())
            ->method('name')
            ->willReturn('primary');

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        self::assertSame('primary', $pooled->name());
    }

    #[Test]
    public function delegatesInTransactionToWrappedConnection(): void
    {
        $wrapped = $this->createMock(ConnectionInterface::class);
        $wrapped->expects(self::once())
            ->method('inTransaction')
            ->willReturn(true);

        $pool = $this->createStub(ConnectionPoolInterface::class);
        $pooled = new PooledConnection($wrapped, $pool, time());

        self::assertTrue($pooled->inTransaction());
    }

    #[Test]
    public function disconnectReturnsToPool(): void
    {
        $wrapped = $this->createMock(ConnectionInterface::class);
        $wrapped->expects(self::never())->method('disconnect');

        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('checkin');

        $pooled = new PooledConnection($wrapped, $pool, time());
        $pooled->disconnect();
    }

    #[Test]
    public function disconnectIsIdempotent(): void
    {
        $wrapped = $this->createStub(ConnectionInterface::class);

        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('checkin');

        $pooled = new PooledConnection($wrapped, $pool, time());
        $pooled->disconnect();
        $pooled->disconnect();
    }

    #[Test]
    public function createdAtReturnsCheckoutTimestamp(): void
    {
        $wrapped = $this->createStub(ConnectionInterface::class);
        $pool = $this->createStub(ConnectionPoolInterface::class);

        $timestamp = time() - 100;
        $pooled = new PooledConnection($wrapped, $pool, $timestamp);

        self::assertSame($timestamp, $pooled->createdAt());
    }

    #[Test]
    public function unwrapReturnsWrappedConnection(): void
    {
        $wrapped = $this->createStub(ConnectionInterface::class);
        $pool = $this->createStub(ConnectionPoolInterface::class);

        $pooled = new PooledConnection($wrapped, $pool, time());

        self::assertSame($wrapped, $pooled->unwrap());
    }
}
