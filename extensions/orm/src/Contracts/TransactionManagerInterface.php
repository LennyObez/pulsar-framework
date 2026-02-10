<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Transaction management for ORM operations.
 */
#[Api(since: '1.0.0')]
interface TransactionManagerInterface
{
    /**
     * Execute a callback within a database transaction.
     *
     * @template T
     * @param callable(ConnectionInterface): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;

    /**
     * Begin a transaction manually.
     */
    public function begin(): void;

    /**
     * Commit the current transaction.
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     */
    public function rollback(): void;

    /**
     * Check if currently inside a transaction.
     */
    public function inTransaction(): bool;
}
