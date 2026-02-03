<?php

declare(strict_types=1);

namespace Pulsar\Database;

use function is_bool;
use function is_int;

use PDO;
use PDOException;
use PDOStatement;
use Pulsar\Database\Exception\DatabaseException;

/**
 * Prepared statement wrapper.
 *
 * Provides a fluent interface for binding parameters and executing statements.
 */
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
