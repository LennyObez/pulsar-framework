<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Generator;
use PDO;
use PDOException;
use PDOStatement;
use Pulsar\Api\Api;
use Pulsar\Database\Exception\DatabaseException;

use function is_bool;
use function is_int;

/**
 * Prepared statement wrapper.
 *
 * Provides a fluent interface for binding parameters and executing statements.
 * @api
 */
#[Api(since: '1.0.0')]
final class Statement
{
    /** @var array<string, mixed> */
    private array $bindings = [];

    public function __construct(
        private readonly PDOStatement $statement,
        private readonly string $sql,
    ) {}

    /**
     * Bind a parameter value.
     */
    public function bind(string $key, mixed $value): self
    {
        $this->bindings[$key] = $value;
        return $this;
    }

    /**
     * Execute the statement as a SELECT query and return a Result.
     *
     * @param array<string, mixed> $bindings Additional bindings (merged with fluent bindings)
     */
    public function execute(array $bindings = []): Result
    {
        $merged = [...$this->bindings, ...$bindings];

        try {
            $this->bindAll($merged);
            $this->statement->execute();

            /** @var list<array<string, mixed>> $data */
            $data = $this->statement->fetchAll(PDO::FETCH_ASSOC);

            return Result::fromArrays($data);
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed($this->sql, $e);
        }
    }

    /**
     * Execute the statement and stream rows lazily via a generator.
     *
     * F11.16: `execute()` materialises the full result set with
     * `fetchAll()`, which OOMs on large queries (an export, a
     * tenant-wide audit dump, a backfill scan). This streaming
     * variant uses PDO's row-at-a-time `fetch()` so memory stays
     * O(1) regardless of result-set size. Trade-off: the generator
     * holds the underlying PDOStatement open for the lifetime of
     * the iteration, so callers MUST drain or break out — leaving
     * a partial iteration sitting around can pin database
     * resources. For known-bounded result sets prefer `execute()`.
     *
     * @param array<string, mixed> $bindings Additional bindings (merged with fluent bindings)
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function executeStreaming(array $bindings = []): Generator
    {
        $merged = [...$this->bindings, ...$bindings];

        try {
            $this->bindAll($merged);
            $this->statement->execute();
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed($this->sql, $e);
        }

        try {
            while (true) {
                /** @var array<string, mixed>|false $row */
                $row = $this->statement->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    return;
                }
                yield $row;
            }
        } finally {
            $this->statement->closeCursor();
        }
    }

    /**
     * Execute the statement as a write query and return the affected row count.
     *
     * @param array<string, mixed> $bindings Additional bindings (merged with fluent bindings)
     */
    public function executeAffecting(array $bindings = []): int
    {
        $merged = [...$this->bindings, ...$bindings];

        try {
            $this->bindAll($merged);
            $this->statement->execute();

            return $this->statement->rowCount();
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed($this->sql, $e);
        }
    }

    /**
     * Bind all parameters with appropriate PDO types.
     *
     * @param array<string, mixed> $bindings
     */
    private function bindAll(array $bindings): void
    {
        /** @var mixed $value */
        foreach ($bindings as $key => $value) {
            $paramType = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            };

            $this->statement->bindValue(':' . ltrim($key, ':'), $value, $paramType);
        }
    }
}
