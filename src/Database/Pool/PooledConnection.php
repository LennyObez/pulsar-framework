<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;

/**
 * Decorator that auto-returns a connection to the pool on disconnect.
 *
 * All connection methods delegate to the wrapped connection.
 * Calling {@see disconnect()} returns the connection to the pool
 * instead of closing the underlying database link.
 */
#[Api(since: '1.0.0')]
final class PooledConnection implements ConnectionInterface
{
    private bool $returned = false;

    public function __construct(
        private readonly ConnectionInterface $wrapped,
        private readonly ConnectionPoolInterface $pool,
        private readonly int $checkedOutAt,
    ) {}

    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        return $this->wrapped->query($sql, $bindings);
    }

    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->wrapped->execute($sql, $bindings);
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        return $this->wrapped->prepare($sql);
    }

    #[Override]
    public function beginTransaction(): Transaction
    {
        return $this->wrapped->beginTransaction();
    }

    #[Override]
    public function transaction(callable $callback): mixed
    {
        return $this->wrapped->transaction($callback);
    }

    #[Override]
    public function lastInsertId(): string
    {
        return $this->wrapped->lastInsertId();
    }

    #[Override]
    public function driver(): Driver
    {
        return $this->wrapped->driver();
    }

    #[Override]
    public function name(): string
    {
        return $this->wrapped->name();
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->wrapped->inTransaction();
    }

    #[Override]
    public function disconnect(): void
    {
        if ($this->returned) {
            return;
        }

        $this->returned = true;
        $this->pool->checkin($this);
    }

    /**
     * Get the timestamp when this connection was originally created.
     */
    public function createdAt(): int
    {
        return $this->checkedOutAt;
    }

    /**
     * Get the underlying unwrapped connection.
     */
    public function unwrap(): ConnectionInterface
    {
        return $this->wrapped;
    }
}
