<?php

declare(strict_types=1);

namespace Pulsar\Database;

use function array_map;
use function count;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Exception\DatabaseException;

/**
 * Readonly query result value object.
 *
 * Eagerly loads all rows from the statement at construction time.
 */
#[Api(since: '1.0.0')]
readonly class Result
{
    /** @var list<Row> */
    public array $rows;
    public int $rowCount;

    /**
     * @param list<Row> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->rowCount = count($rows);
    }

    /**
     * Build a Result from raw PDO fetch-all data.
     *
     * @param list<array<string, mixed>> $data
     */
    #[NoDiscard]
    public static function fromArrays(array $data): self
    {
        return new self(array_map(
            static fn(array $row): Row => new Row($row),
            $data,
        ));
    }

    /**
     * Get the first row, or null if the result set is empty.
     */
    public function first(): ?Row
    {
        return $this->rows[0] ?? null;
    }

    /**
     * Get the first row, or throw if the result set is empty.
     *
     * @throws DatabaseException
     */
    public function firstOrFail(): Row
    {
        if ($this->rows === []) {
            throw DatabaseException::emptyResult();
        }

        return $this->rows[0];
    }

    /**
     * Extract a single column from all rows.
     *
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        return array_map(
            static fn(Row $row): mixed => $row->get($column),
            $this->rows,
        );
    }

    /**
     * Check if the result set is empty.
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Map over all rows with a callable.
     *
     * @template T
     * @param callable(Row): T $callback
     * @return list<T>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->rows);
    }
}
