<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Stub;

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;
use RuntimeException;
use Throwable;

use function array_shift;

/**
 * In-memory connection stub for schema builder testing.
 *
 * Records all executed SQL statements without a real database.
 */
final class InMemoryConnection implements ConnectionInterface
{
    /** @var list<string> */
    private array $executed = [];

    private bool $inTransaction = false;

    /** @var list<Result> */
    private array $queryResults = [];

    public function __construct(
        private readonly Driver $driver,
    ) {}

    public function query(string $sql, array $bindings = []): Result
    {
        $this->executed[] = $sql;

        if ($this->queryResults !== []) {
            return array_shift($this->queryResults);
        }

        return new Result([]);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->executed[] = $sql;

        return 0;
    }

    public function prepare(string $sql): Statement
    {
        throw new RuntimeException('Not implemented in stub');
    }

    public function beginTransaction(): Transaction
    {
        throw new RuntimeException('Not implemented in stub — use transaction() callback instead');
    }

    public function transaction(callable $callback): mixed
    {
        $this->inTransaction = true;

        try {
            $result = $callback($this);
            $this->inTransaction = false;

            return $result;
        } catch (Throwable $e) {
            $this->inTransaction = false;

            throw $e;
        }
    }

    public function lastInsertId(): string
    {
        return '0';
    }

    public function driver(): Driver
    {
        return $this->driver;
    }

    public function name(): string
    {
        return 'test';
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function disconnect(): void {}

    /**
     * Get all SQL statements that were executed.
     *
     * @return list<string>
     */
    public function executedStatements(): array
    {
        return $this->executed;
    }

    /**
     * Clear recorded statements.
     */
    public function reset(): void
    {
        $this->executed = [];
        $this->queryResults = [];
    }

    /**
     * Enqueue a result to be returned by the next query() call.
     */
    public function enqueueQueryResult(Result $result): void
    {
        $this->queryResults[] = $result;
    }
}
