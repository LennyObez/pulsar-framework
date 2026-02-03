<?php

declare(strict_types=1);

namespace Pulsar\Database;

use PDO;
use PDOException;
use Pulsar\Database\Exception\DatabaseException;

use function sprintf;

/**
 * Transaction handle with savepoint-based nesting support.
 *
 * Depth 0 = real BEGIN/COMMIT/ROLLBACK.
 * Depth > 0 = SAVEPOINT/RELEASE SAVEPOINT/ROLLBACK TO SAVEPOINT.
 */
final class Transaction
{
    private bool $active = true;
    private bool $committed = false;
    private bool $rolledBack = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $depth,
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
    }

    /**
     * Whether the transaction is still active (not yet committed or rolled back).
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Whether the transaction was committed.
     */
    public function isCommitted(): bool
    {
        return $this->committed;
    }

    /**
     * Whether the transaction was rolled back.
     */
    public function isRolledBack(): bool
    {
        return $this->rolledBack;
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
