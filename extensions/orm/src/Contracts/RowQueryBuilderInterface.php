<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Domain\AggregateBuilder;
use Pulsar\Extension\Orm\Domain\LikePattern;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Domain\SortDirection;

/**
 * Read-only query builder for raw row results.
 *
 * No insert/update/delete on the public interface: all writes
 * go through repositories with MutationContext.
 * @api
 */
#[Api(since: '1.0.0')]
interface RowQueryBuilderInterface
{
    /**
     * Set the columns to select.
     *
     * @param list<string|RawExpression> $columns
     */
    public function select(array $columns): RowQueryBuilderInterface;

    /**
     * Add a WHERE equality condition.
     */
    public function where(string $column, mixed $value): RowQueryBuilderInterface;

    /**
     * Add a WHERE condition with a comparison operator.
     */
    public function whereOp(string $column, string $operator, mixed $value): RowQueryBuilderInterface;

    /**
     * Add a WHERE column IS NULL condition.
     */
    public function whereNull(string $column): RowQueryBuilderInterface;

    /**
     * Add a WHERE column IS NOT NULL condition.
     */
    public function whereNotNull(string $column): RowQueryBuilderInterface;

    /**
     * Add a WHERE column IN (...) condition.
     *
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): RowQueryBuilderInterface;

    /**
     * Add a WHERE column NOT IN (...) condition.
     *
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): RowQueryBuilderInterface;

    /**
     * Add a WHERE column BETWEEN low AND high condition.
     */
    public function whereBetween(string $column, mixed $low, mixed $high): RowQueryBuilderInterface;

    /**
     * Add a WHERE LIKE condition.
     */
    public function whereLike(string $column, LikePattern $pattern): RowQueryBuilderInterface;

    /**
     * Add a raw WHERE condition.
     */
    public function whereRaw(RawExpression $expression): RowQueryBuilderInterface;

    /**
     * Add an OR WHERE condition group.
     *
     * The callback receives a fresh WhereGroup builder. All conditions
     * added inside the callback are combined with AND, then the entire
     * group is OR-ed with the previous WHERE clauses.
     *
     * @param callable(RowQueryBuilderInterface): void $callback
     */
    public function orWhere(callable $callback): RowQueryBuilderInterface;

    /**
     * Add an ORDER BY clause.
     */
    public function orderBy(string $column, SortDirection $direction = SortDirection::Asc): RowQueryBuilderInterface;

    /**
     * Set the maximum number of rows to return.
     */
    public function limit(int $limit): RowQueryBuilderInterface;

    /**
     * Set the row offset.
     */
    public function offset(int $offset): RowQueryBuilderInterface;

    /**
     * Add a GROUP BY column.
     */
    public function groupBy(string $column): RowQueryBuilderInterface;

    /**
     * Add a HAVING condition.
     */
    public function having(RawExpression $expression): RowQueryBuilderInterface;

    /**
     * Set the lock mode.
     */
    public function lock(LockMode $mode): RowQueryBuilderInterface;

    /**
     * Execute the query and return raw rows.
     */
    public function get(): Result;

    /**
     * Execute the query and return the first row.
     */
    public function first(): ?Row;

    /**
     * Get an aggregate builder for this query.
     */
    public function aggregate(): AggregateBuilder;

    /**
     * Get the compiled SQL and bindings without executing.
     *
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    public function toSql(): array;
}
