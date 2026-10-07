<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Transaction;

use function func_num_args;

/**
 * Tests the onFinish callback mechanism in Transaction.
 */
#[CoversClass(Transaction::class)]
final class TransactionCallbackTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    #[Test]
    public function onFinishCallbackInvokedOnCommit(): void
    {
        $this->pdo->beginTransaction();

        $callbackCount = 0;
        $transaction = new Transaction($this->pdo, 0, function () use (&$callbackCount): void {
            $callbackCount++;
        });

        self::assertSame(0, $callbackCount);

        $transaction->commit();

        self::assertSame(1, $callbackCount);
    }

    #[Test]
    public function onFinishCallbackInvokedOnRollback(): void
    {
        $this->pdo->beginTransaction();

        $callbackFired = false;
        $transaction = new Transaction($this->pdo, 0, function () use (&$callbackFired): void {
            $callbackFired = true;
        });

        $transaction->rollback();

        self::assertTrue($callbackFired);
    }

    #[Test]
    public function nullCallbackDoesNotCauseError(): void
    {
        $this->pdo->beginTransaction();

        $transaction = new Transaction($this->pdo, 0, null);

        $transaction->commit();

        self::assertTrue($transaction->committed);
    }

    #[Test]
    public function savepointCommitInvokesCallback(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('SAVEPOINT pulsar_sp_1');

        $callbackFired = false;
        $transaction = new Transaction($this->pdo, 1, function () use (&$callbackFired): void {
            $callbackFired = true;
        });

        $transaction->commit();

        self::assertTrue($callbackFired);
        self::assertTrue($transaction->committed);

        $this->pdo->rollBack();
    }

    #[Test]
    public function savepointRollbackInvokesCallback(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('SAVEPOINT pulsar_sp_1');

        $callbackFired = false;
        $transaction = new Transaction($this->pdo, 1, function () use (&$callbackFired): void {
            $callbackFired = true;
        });

        $transaction->rollback();

        self::assertTrue($callbackFired);
        self::assertTrue($transaction->rolledBack);

        $this->pdo->rollBack();
    }

    #[Test]
    public function callbackReceivesNoArguments(): void
    {
        $this->pdo->beginTransaction();

        $receivedArgCount = -1;
        $transaction = new Transaction($this->pdo, 0, function () use (&$receivedArgCount): void {
            $receivedArgCount = func_num_args();
        });

        $transaction->commit();

        self::assertSame(0, $receivedArgCount);
    }
}
