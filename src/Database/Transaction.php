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
 * @api
 */
#[Api(since: '1.0.0')]
final class Transaction
{
    public private(set) bool $active = true;
    public private(set) bool $committed = false;
    public private(set) bool $rolledBack = false;

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
     * @throws DatabaseException If already finished.
     */
    public function commit(): void
    {
        $this->assertActive();

        try {
            if ($this->depth === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec(sprintf('RELEASE SAVEPOINT %s', $this->savepointName()));
            }
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed('COMMIT/RELEASE SAVEPOINT', $e);
        }

        $this->active = false;
        $this->committed = true;
        $this->onFinish?->__invoke();
    }

    /**
     * Roll back the transaction (or rollback to the savepoint).
     *
     * @throws DatabaseException If already finished.
     */
    public function rollback(): void
    {
        $this->assertActive();

        try {
            if ($this->depth === 0) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec(sprintf('ROLLBACK TO SAVEPOINT %s', $this->savepointName()));
            }
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed('ROLLBACK/ROLLBACK TO SAVEPOINT', $e);
        }

        $this->active = false;
        $this->rolledBack = true;
        $this->onFinish?->__invoke();
    }

    /**
     * Get the nesting depth of this transaction.
     */
    public function depth(): int
    {
        return $this->depth;
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
