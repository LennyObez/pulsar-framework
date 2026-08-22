<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use Override;
use PDO;
use PDOException;
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

    /**
     * A COMMIT can fail and leave the transaction open — `SQLITE_BUSY` is the documented
     * case, and it is retryable.
     *
     * The handle must stay live there, because {@see \Pulsar\Database\PdoConnection
     * ::transaction()} guards its rollback with `if ($transaction->active)`. A handle that
     * declares itself finished strands the write: the framework's depth reads 0, the
     * engine still holds an open transaction, and nothing can retry or withdraw it. On
     * SQLite that also holds the PENDING lock against every other process until PHP exits.
     */
    #[Test]
    public function aRetryableCommitFailureLeavesTheHandleLive(): void
    {
        $unwound = 0;
        $pdo = $this->pdoWhoseCommitFails(transactionSurvives: true);
        $transaction = new Transaction($pdo, 0, function () use (&$unwound): void {
            $unwound++;
        });

        try {
            $transaction->commit();
            self::fail('a failing COMMIT must not be reported as a success');
        } catch (DatabaseException) {
            // Expected; what matters is the state it leaves behind.
        }

        self::assertTrue($transaction->active, 'the caller must still be able to roll back');
        self::assertFalse($transaction->committed);
        self::assertSame(0, $unwound, 'the depth is still held: the engine still has the transaction');
    }

    /**
     * The mirror case: the engine ended the transaction as part of failing the COMMIT —
     * a deferred-constraint violation at COMMIT does this. Nothing is left to withdraw,
     * so the handle finishes and the connection's depth unwinds. Failing to unwind here
     * was the original defect: every later transaction issued SAVEPOINT instead of BEGIN
     * and none of them could be committed.
     */
    #[Test]
    public function aTerminalCommitFailureFinishesTheHandleAndUnwindsTheDepth(): void
    {
        $unwound = 0;
        $pdo = $this->pdoWhoseCommitFails(transactionSurvives: false);
        $transaction = new Transaction($pdo, 0, function () use (&$unwound): void {
            $unwound++;
        });

        try {
            $transaction->commit();
            self::fail('a failing COMMIT must not be reported as a success');
        } catch (DatabaseException) {
            // Expected.
        }

        self::assertFalse($transaction->active);
        self::assertFalse($transaction->committed);
        self::assertSame(1, $unwound, 'the depth must unwind or the connection is poisoned');
    }

    /**
     * When the engine ended the transaction on its own, `commit()` must not claim it
     * committed.
     *
     * MySQL commits implicitly at every DDL statement, which is the case this branch
     * exists for — but an engine-side rollback reaches the identical state, and
     * `PDO::inTransaction()` is false for both. Reporting `committed` would be a guess
     * presented as a fact.
     */
    #[Test]
    public function commitDoesNotClaimSuccessWhenTheEngineEndedTheTransaction(): void
    {
        $transaction = new Transaction($this->pdo, 0);

        $transaction->commit();

        self::assertFalse($transaction->active);
        self::assertFalse($transaction->committed, 'nothing here knows whether it committed or rolled back');
        self::assertTrue($transaction->endedByEngine);
    }

    /**
     * And a rollback in that state must say so rather than report a withdrawal that did
     * not happen — after a DDL statement the changes are permanent.
     */
    #[Test]
    public function rollbackRefusesWhenTheEngineAlreadyEndedTheTransaction(): void
    {
        $transaction = new Transaction($this->pdo, 0);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('Rollback is impossible');

        $transaction->rollback();
    }

    /**
     * A PDO whose COMMIT fails, with control over whether the transaction survives it.
     *
     * The state has to change across the call, not merely be fixed: `commit()` consults
     * `inTransaction()` *before* deciding to issue the COMMIT, and again afterwards to
     * decide whether the handle is finished. A fake that answers `false` throughout never
     * reaches the COMMIT at all — which is how the first version of this fixture made its
     * own test fail, correctly.
     */
    private function pdoWhoseCommitFails(bool $transactionSurvives): PDO
    {
        return new class ($transactionSurvives) extends PDO {
            private bool $open = true;

            public function __construct(private readonly bool $survives)
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            #[Override]
            public function commit(): bool
            {
                $this->open = $this->survives;

                throw new PDOException('database is locked');
            }

            #[Override]
            public function inTransaction(): bool
            {
                return $this->open;
            }
        };
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
        $this->expectExceptionMessageIsOrContains('Transaction has already been committed or rolled back');

        $transaction->commit();
    }

    #[Test]
    public function doubleRollbackThrows(): void
    {
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo, 0);

        $transaction->rollback();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('Transaction has already been committed or rolled back');

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
