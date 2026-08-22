<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Closure;
use PDO;
use PDOException;
use Pulsar\Api\Api;
use Pulsar\Database\Exception\DatabaseException;

use function sprintf;

/**
 * Transaction handle with savepoint-based nesting support.
 *
 * Depth 0 = real BEGIN/COMMIT/ROLLBACK.
 * Depth > 0 = SAVEPOINT/RELEASE SAVEPOINT/ROLLBACK TO SAVEPOINT.
 *
 * ## Two questions this class keeps apart
 *
 * "Is this handle finished?" and "is the engine's transaction over?" are not the same
 * question, and conflating them is how both of this class's past defects were made.
 *
 * Marking the handle finished too late leaks the connection's depth counter: every later
 * transaction issues SAVEPOINT instead of BEGIN and none of them can be committed.
 *
 * Marking it finished too early is worse. {@see PdoConnection::transaction()} guards its
 * rollback with `if ($transaction->active)`, so a handle that declares itself finished
 * after a COMMIT that did not take strands the write: the framework's depth reads 0, the
 * engine still holds an open transaction, and the handle can neither retry nor withdraw.
 * On SQLite that also holds the PENDING lock against every other process until PHP exits.
 *
 * So the handle is finished exactly when the engine says its transaction is over, and
 * {@see finishIfEngineIsDone()} is the only place that decides.
 * @api
 */
#[Api(since: '1.0.0')]
final class Transaction
{
    public private(set) bool $active = true;
    public private(set) bool $committed = false;
    public private(set) bool $rolledBack = false;

    /**
     * The engine ended this transaction on its own, and PDO cannot say how.
     *
     * MySQL and SQLite commit implicitly at every DDL statement, which is the case this
     * exists for. But an engine-side rollback reaches the identical state — SQLite's
     * `ON CONFLICT ROLLBACK`, MySQL after a deadlock — and `PDO::inTransaction()` is
     * `false` for both. Reporting {@see $committed} here would be a guess presented as a
     * fact, so it stays false and this says what is actually known.
     */
    public private(set) bool $endedByEngine = false;

    /**
     * @param Closure(): void $onFinish Callback invoked after commit or rollback to update connection state.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $depth,
        private readonly ?Closure $onFinish = null,
    ) {}

    /**
     * Commit the transaction (or release the savepoint).
     *
     * @throws DatabaseException If already finished, or if the commit itself fails.
     */
    public function commit(): void
    {
        $this->assertActive();

        try {
            if ($this->depth > 0) {
                $this->pdo->exec(sprintf('RELEASE SAVEPOINT %s', $this->savepointName()));
                $this->committed = true;
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->commit();
                $this->committed = true;
            } else {
                $this->endedByEngine = true;
            }
        } catch (PDOException $e) {
            $this->finishIfEngineIsDone();

            throw DatabaseException::queryFailed('COMMIT/RELEASE SAVEPOINT', $e);
        }

        $this->finish();
    }

    /**
     * Roll back the transaction (or rollback to the savepoint).
     *
     * @throws DatabaseException If already finished, if the rollback fails, or if the
     *         engine already ended the transaction and there is nothing left to withdraw.
     */
    public function rollback(): void
    {
        $this->assertActive();

        if ($this->depth === 0 && !$this->pdo->inTransaction()) {
            // Deliberately not silent. The caller asked for the work to be withdrawn and
            // it cannot be: the engine ended the transaction, and after a DDL statement
            // that means the changes are permanent. Reporting a rollback that did not
            // happen is the one answer that must not be given.
            $this->endedByEngine = true;
            $this->finish();

            throw DatabaseException::rollbackImpossibleAfterImplicitCommit();
        }

        try {
            if ($this->depth > 0) {
                $this->pdo->exec(sprintf('ROLLBACK TO SAVEPOINT %s', $this->savepointName()));
            } else {
                $this->pdo->rollBack();
            }

            $this->rolledBack = true;
        } catch (PDOException $e) {
            $this->finishIfEngineIsDone();

            throw DatabaseException::queryFailed('ROLLBACK/ROLLBACK TO SAVEPOINT', $e);
        }

        $this->finish();
    }

    /**
     * Get the nesting depth of this transaction.
     */
    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * Finish only if the engine has nothing left for this handle to withdraw.
     *
     * A COMMIT can fail and leave the transaction open — `SQLITE_BUSY` is the documented
     * case — and that failure is retryable. Declaring the handle finished there is what
     * strands the write, because the caller's rollback is then skipped. Staying live
     * costs a depth that unwinds on the rollback which follows; going finished costs the
     * connection.
     */
    private function finishIfEngineIsDone(): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }

        $this->finish();
    }

    private function finish(): void
    {
        $this->active = false;
        $this->onFinish?->__invoke();
    }

    private function savepointName(): string
    {
        return sprintf('pulsar_sp_%d', $this->depth);
    }

    /**
     * @throws DatabaseException
     */
    private function assertActive(): void
    {
        if (!$this->active) {
            throw DatabaseException::transactionAlreadyFinished();
        }
    }
}
