<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Transaction;
use Pulsar\Extension\Orm\Features\Persistence\TransactionManager;

#[CoversClass(TransactionManager::class)]
final class TransactionManagerTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        // Use a real SQLite PDO for constructing real Transaction objects
        $this->pdo = new PDO('sqlite::memory:');
    }

    #[Test]
    public function transactionalDelegatesToConnection(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('transaction')
            ->with(self::isCallable())
            ->willReturn('result');

        $manager = new TransactionManager($connection);
        $result = $manager->transactional(static fn() => 'result');

        self::assertSame('result', $result);
    }

    #[Test]
    public function beginCallsConnectionBeginTransaction(): void
    {
        $transaction = new Transaction($this->pdo, 0);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturn($transaction);

        $manager = new TransactionManager($connection);
        $manager->begin();
    }

    #[Test]
    public function commitCallsTransactionCommit(): void
    {
        // Start a real SQLite transaction so commit() works
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $this->connection->method('beginTransaction')->willReturn($transaction);

        $manager = new TransactionManager($this->connection);
        $manager->begin();
        $manager->commit();

        self::assertTrue($transaction->committed);
        self::assertFalse($transaction->active);
    }

    #[Test]
    public function rollbackCallsTransactionRollback(): void
    {
        // Start a real SQLite transaction so rollback() works
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $this->connection->method('beginTransaction')->willReturn($transaction);

        $manager = new TransactionManager($this->connection);
        $manager->begin();
        $manager->rollback();

        self::assertTrue($transaction->rolledBack);
        self::assertFalse($transaction->active);
    }

    #[Test]
    public function commitWithoutBeginIsNoOp(): void
    {
        $this->connection->method('inTransaction')->willReturn(false);
        $manager = new TransactionManager($this->connection);

        // Should not throw -- gracefully handles no active transaction
        $manager->commit();

        // No transaction was started, so the connection reports not-in-transaction
        self::assertFalse($manager->inTransaction());
    }

    #[Test]
    public function rollbackWithoutBeginIsNoOp(): void
    {
        $this->connection->method('inTransaction')->willReturn(false);
        $manager = new TransactionManager($this->connection);

        // Should not throw -- gracefully handles no active transaction
        $manager->rollback();

        self::assertFalse($manager->inTransaction());
    }

    #[Test]
    public function rollbackSkipsInactiveTransaction(): void
    {
        // Start and commit a transaction, making it inactive
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);
        $transaction->commit();

        $this->connection->method('beginTransaction')->willReturn($transaction);

        $manager = new TransactionManager($this->connection);
        $manager->begin();

        // Transaction is no longer active, rollback should be a no-op
        $manager->rollback();

        // transaction->rollback() was NOT called (it's inactive)
        self::assertTrue($transaction->committed);
    }

    #[Test]
    public function inTransactionDelegatesToConnection(): void
    {
        $this->connection->method('inTransaction')->willReturn(true);

        $manager = new TransactionManager($this->connection);

        self::assertTrue($manager->inTransaction());
    }

    #[Test]
    public function inTransactionReturnsFalseWhenNotInTransaction(): void
    {
        $this->connection->method('inTransaction')->willReturn(false);

        $manager = new TransactionManager($this->connection);

        self::assertFalse($manager->inTransaction());
    }

    #[Test]
    public function commitClearsCurrentTransaction(): void
    {
        // Start a real SQLite transaction
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $this->connection->method('beginTransaction')->willReturn($transaction);

        $manager = new TransactionManager($this->connection);
        $manager->begin();
        $manager->commit();

        // Second commit should be a no-op (current transaction was cleared)
        // This should not throw or call commit again
        $manager->commit();

        self::assertTrue($transaction->committed);
    }
}
