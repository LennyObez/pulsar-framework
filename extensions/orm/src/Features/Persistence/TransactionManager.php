<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Persistence;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Transaction;
use Pulsar\Extension\Orm\Contracts\TransactionManagerInterface;

/**
 * Transaction management implementation wrapping the database connection.
 */
#[Internal]
final class TransactionManager implements TransactionManagerInterface
{
    private ?Transaction $currentTransaction = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    #[Override]
    public function transactional(callable $callback): mixed
    {
        return $this->connection->transaction($callback);
    }

    #[Override]
    public function begin(): void
    {
        $this->currentTransaction = $this->connection->beginTransaction();
    }

    #[Override]
    public function commit(): void
    {
        if ($this->currentTransaction !== null) {
            $this->currentTransaction->commit();
            $this->currentTransaction = null;
        }
    }

    #[Override]
    public function rollback(): void
    {
        if ($this->currentTransaction !== null && $this->currentTransaction->active) {
            $this->currentTransaction->rollback();
            $this->currentTransaction = null;
        }
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }
}
