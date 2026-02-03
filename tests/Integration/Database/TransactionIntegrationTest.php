<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Transaction;
use RuntimeException;

#[CoversClass(PdoConnection::class)]
#[CoversClass(Transaction::class)]
final class TransactionIntegrationTest extends TestCase
{
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(
            'CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INTEGER NOT NULL)',
        );
        $this->connection->execute(
            'INSERT INTO accounts (id, balance) VALUES (:id, :balance)',
            ['id' => 1, 'balance' => 1000],
        );
    }

    #[Test]
    public function commitPersistsChanges(): void
    {
        $tx = $this->connection->beginTransaction();

        $this->connection->execute(
            'UPDATE accounts SET balance = :balance WHERE id = :id',
            ['balance' => 500, 'id' => 1],
        );

        $tx->commit();

        $result = $this->connection->query('SELECT balance FROM accounts WHERE id = 1');
        self::assertSame(500, $result->firstOrFail()->getInt('balance'));
    }

    #[Test]
    public function rollbackRevertChanges(): void
    {
        $tx = $this->connection->beginTransaction();

        $this->connection->execute(
            'UPDATE accounts SET balance = :balance WHERE id = :id',
            ['balance' => 0, 'id' => 1],
        );

        $tx->rollback();

        $result = $this->connection->query('SELECT balance FROM accounts WHERE id = 1');
        self::assertSame(1000, $result->firstOrFail()->getInt('balance'));
    }

    #[Test]
    public function transactionCallbackAutoCommitsOnSuccess(): void
    {
        $returned = $this->connection->transaction(function ($conn) {
            $conn->execute(
                'UPDATE accounts SET balance = :balance WHERE id = :id',
                ['balance' => 750, 'id' => 1],
            );

            return 'done';
        });

        self::assertSame('done', $returned);

        $result = $this->connection->query('SELECT balance FROM accounts WHERE id = 1');
        self::assertSame(750, $result->firstOrFail()->getInt('balance'));
    }

    #[Test]
    public function transactionCallbackAutoRollsBackOnException(): void
    {
        try {
            $this->connection->transaction(function ($conn): void {
                $conn->execute(
                    'UPDATE accounts SET balance = :balance WHERE id = :id',
                    ['balance' => 0, 'id' => 1],
                );

                throw new RuntimeException('Something went wrong');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $result = $this->connection->query('SELECT balance FROM accounts WHERE id = 1');
        self::assertSame(1000, $result->firstOrFail()->getInt('balance'));
    }

    #[Test]
    public function inTransactionReturnsTrueDuringTransaction(): void
    {
        self::assertFalse($this->connection->inTransaction());

        $this->connection->transaction(function ($conn): void {
            self::assertTrue($conn->inTransaction());
        });

        self::assertFalse($this->connection->inTransaction());
    }

    #[Test]
    public function nestedSavepointCommit(): void
    {
        $outer = $this->connection->beginTransaction();

        $this->connection->execute(
            'UPDATE accounts SET balance = :balance WHERE id = :id',
            ['balance' => 800, 'id' => 1],
        );

        $inner = $this->connection->beginTransaction();

        $this->connection->execute(
            'UPDATE accounts SET balance = :balance WHERE id = :id',
            ['balance' => 600, 'id' => 1],
        );

        $inner->commit();
        $outer->commit();

        $result = $this->connection->query('SELECT balance FROM accounts WHERE id = 1');
        self::assertSame(600, $result->firstOrFail()->getInt('balance'));
    }

    #[Test]
    public function nestedSavepointRollbackPreservesOuterChanges(): void
    {
        $outer = $this->connection->beginTransaction();

        $this->connection->execute(
            'UPDATE accounts SET balance = :balance WHERE id = :id',
            ['balance' => 800, 'id' => 1],
        );

        $inner = $this->connection->beginTransaction();

        $this->connection->execute(
            'UPDATE accounts SET balance = :balance WHERE id = :id',
            ['balance' => 0, 'id' => 1],
        );

        $inner->rollback();

        // After inner rollback, balance should be back to 800 (outer's change)
        $midResult = $this->connection->query('SELECT balance FROM accounts WHERE id = 1');
        self::assertSame(800, $midResult->firstOrFail()->getInt('balance'));

        $outer->commit();

        $result = $this->connection->query('SELECT balance FROM accounts WHERE id = 1');
        self::assertSame(800, $result->firstOrFail()->getInt('balance'));
    }
}
