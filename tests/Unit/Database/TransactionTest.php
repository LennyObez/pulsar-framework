<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Transaction;

#[CoversClass(Transaction::class)]
final class TransactionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    #[Test]
    public function newTransactionIsActive(): void
    {
        $transaction = new Transaction($this->pdo, 0);

        self::assertTrue($transaction->active);
        self::assertFalse($transaction->committed);
        self::assertFalse($transaction->rolledBack);
    }

    #[Test]
    public function commitMarksTransactionAsCommitted(): void
    {
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $transaction->commit();

        self::assertFalse($transaction->active);
        self::assertTrue($transaction->committed);
        self::assertFalse($transaction->rolledBack);
    }

    #[Test]
    public function rollbackMarksTransactionAsRolledBack(): void
    {
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $transaction->rollback();

        self::assertFalse($transaction->active);
        self::assertFalse($transaction->committed);
        self::assertTrue($transaction->rolledBack);
    }

    #[Test]
    public function doubleCommitThrows(): void
    {
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $transaction->commit();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Transaction has already been committed or rolled back');

        $transaction->commit();
    }

    #[Test]
    public function doubleRollbackThrows(): void
    {
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $transaction->rollback();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Transaction has already been committed or rolled back');

        $transaction->rollback();
    }

    #[Test]
    public function commitAfterRollbackThrows(): void
    {
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $transaction->rollback();

        $this->expectException(DatabaseException::class);

        $transaction->commit();
    }

    #[Test]
    public function rollbackAfterCommitThrows(): void
    {
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $transaction->commit();

        $this->expectException(DatabaseException::class);

        $transaction->rollback();
    }

    #[Test]
    public function depthIsRecorded(): void
    {
        $transaction = new Transaction($this->pdo, 3);

        self::assertSame(3, $transaction->depth());
    }

    #[Test]
    public function savepointCommitAtDepthGreaterThanZero(): void
    {
        // Start a real transaction first so SQLite is in a transaction state
        $this->pdo->beginTransaction();

        // Create a savepoint
        $this->pdo->exec('SAVEPOINT pulsar_sp_1');
        $transaction = new Transaction($this->pdo, 1);

        // Commit (release savepoint) should not throw
        $transaction->commit();

        self::assertTrue($transaction->committed);

        // Clean up
        $this->pdo->rollBack();
    }

    #[Test]
    public function savepointRollbackAtDepthGreaterThanZero(): void
    {
        // Start a real transaction first
        $this->pdo->beginTransaction();

        // Create a savepoint
        $this->pdo->exec('SAVEPOINT pulsar_sp_1');
        $transaction = new Transaction($this->pdo, 1);

        // Rollback (rollback to savepoint) should not throw
        $transaction->rollback();

        self::assertTrue($transaction->rolledBack);

        // Clean up
        $this->pdo->rollBack();
    }
}
