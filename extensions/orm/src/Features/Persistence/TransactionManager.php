<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Persistence;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Transaction;
use Pulsar\Extension\Orm\Contracts\TransactionManagerInterface;

use function array_pop;

/**
 * Transaction management implementation wrapping the database connection.
 */
#[Internal]
final class TransactionManager implements TransactionManagerInterface
{
    /**
     * Stack of open transaction handles, one per nesting level. A single
     * reference would be overwritten by a nested begin(), losing the outer
     * handle and leaving its real transaction open and untracked; the stack
     * retains every level until it is finished in last-in-first-out order,
     * matching the savepoint depth tracked by the connection.
     *
     * @var list<Transaction>
     */
    private array $transactions = [];

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
        $this->transactions[] = $this->connection->beginTransaction();
    }

    #[Override]
    public function commit(): void
    {
        $transaction = array_pop($this->transactions);

        $transaction?->commit();
    }

    #[Override]
    public function rollback(): void
    {
        $transaction = array_pop($this->transactions);

        if ($transaction !== null && $transaction->active) {
            $transaction->rollback();
        }
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }
}
