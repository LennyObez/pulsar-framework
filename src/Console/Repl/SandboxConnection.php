<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Dialect\DialectInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;

/**
 * Sandbox database connection that wraps all operations in a transaction.
 *
 * On session end, the transaction is rolled back so no writes persist.
 * Unlike ReadOnlyConnection, this allows write queries during the session
 * but guarantees they are never committed.
 */
#[Internal]
final class SandboxConnection implements ConnectionInterface
{
    private ?Transaction $transaction = null;

    public function __construct(
        private readonly ConnectionInterface $inner,
    ) {}

    /**
     * Begin the sandbox transaction. Must be called before any queries.
     */
    public function begin(): void
    {
        if ($this->transaction === null) {
            $this->transaction = $this->inner->beginTransaction();
        }
    }

    /**
     * Roll back the sandbox transaction, discarding all changes.
     */
    public function rollback(): void
    {
        $this->transaction?->rollback();
        $this->transaction = null;
    }

    /**
     * Whether the sandbox transaction is active.
     */
    public function isActive(): bool
    {
        return $this->transaction !== null;
    }

    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        return $this->inner->query($sql, $bindings);
    }

    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->inner->execute($sql, $bindings);
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        return $this->inner->prepare($sql);
    }

    /**
     * Begin a nested transaction (savepoint).
     *
     * The sandbox wrapping transaction remains the outermost. Nested
     * transactions use savepoints so they can be committed/rolled back
     * independently, but the outermost sandbox transaction always
     * rolls back at session end.
     */
    #[Override]
    public function beginTransaction(): Transaction
    {
        return $this->inner->beginTransaction();
    }

    #[Override]
    public function transaction(callable $callback): mixed
    {
        return $this->inner->transaction($callback);
    }

    #[Override]
    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }

    #[Override]
    public function driver(): Driver
    {
        return $this->inner->driver();
    }

    #[Override]
    public function variant(): DriverVariant
    {
        return $this->inner->variant();
    }

    #[Override]
    public function dialect(): DialectInterface
    {
        return $this->inner->dialect();
    }

    #[Override]
    public function name(): string
    {
        return $this->inner->name();
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    #[Override]
    public function disconnect(): void
    {
        $this->rollback();
        $this->inner->disconnect();
    }
}
