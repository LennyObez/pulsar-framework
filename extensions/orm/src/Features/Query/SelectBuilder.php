<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use function array_merge;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\EntityQueryBuilderInterface;
use Pulsar\Extension\Orm\Domain\AggregateBuilder;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Domain\IdentifierValidator;
use Pulsar\Extension\Orm\Domain\LikePattern;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Domain\SortDirection;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;
use Pulsar\Extension\Orm\Internal\Compiler\SqlCompiler;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function sprintf;

/**
 * Fluent read-only SELECT query builder.
 *
 * Implements both RowQueryBuilderInterface (raw rows) and
 * EntityQueryBuilderInterface (hydrated entities).
 */
#[Api(since: '1.0.0')]
final class SelectBuilder implements EntityQueryBuilderInterface
{
    private readonly IdentifierQuoter $quoter;
    private readonly ExpressionCompiler $exprCompiler;
    private readonly BindingCounter $bindingCounter;

    /** @var list<string|RawExpression> */
    private array $columns = ['*'];

    private string $baseTable;
    private string $baseAlias;

    /** @var list<JoinClause> */
    private array $joins = [];

    /** @var list<Expression> */
    private array $wheres = [];

    /** @var list<string> */
    private array $groupBys = [];

    /** @var list<Expression> */
    private array $havings = [];

    /** @var list<string> */
    private array $orderBys = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private LockMode $lockMode = LockMode::None;

    /** @var array<string, mixed> */
    private array $bindings = [];

    private ?FetchPlan $fetchPlan = null;
    private bool $includeTrashed = false;
    private bool $onlyTrashed = false;

    /** @var array<string, string> Map of alias => table name (for conflict detection) */
    private array $aliasMap = [];

    private ?EntityMetadata $metadata = null;
    private ?EntityHydratorInterface $hydrator = null;

    /** @var class-string|null */
    private ?string $entityClass = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->quoter = new IdentifierQuoter($connection->driver());
        $this->bindingCounter = new BindingCounter();
        $this->exprCompiler = new ExpressionCompiler($this->quoter, $this->bindingCounter);
    }

    /**
     * Set the base table and alias for the query.
     */
    public function from(string $table, string $alias = 't0'): self
    {
        IdentifierValidator::validate($table);
        IdentifierValidator::validate($alias);
        $this->baseTable = $table;
        $this->baseAlias = $alias;
        $this->aliasMap[$alias] = $table;

        return $this;
    }

    /**
     * Configure this builder for entity-aware queries.
     *
     * @param class-string $entityClass
     */
    public function forEntity(
        string $entityClass,
        EntityMetadata $metadata,
        EntityHydratorInterface $hydrator,
    ): self {
        $this->entityClass = $entityClass;
        $this->metadata = $metadata;
        $this->hydrator = $hydrator;

        return $this->from($metadata->tableName);
    }

    /**
     * Add a raw JOIN clause.
     */
    public function join(string $table, string $alias, string $type, callable $onCallback): self
    {
        IdentifierValidator::validate($table);
        IdentifierValidator::validate($alias);

        if (isset($this->aliasMap[$alias])) {
            throw QueryBuilderException::aliasConflict($alias);
        }
        $this->aliasMap[$alias] = $table;

        $onBuilder = new JoinOnBuilder($this->quoter, $this->bindingCounter);
        $onCallback($onBuilder);
        $compiled = $onBuilder->compile();

        $tableExpr = sprintf(
            '%s AS %s',
            $this->quoter->quote($table),
            $this->quoter->quote($alias),
        );

        $this->joins[] = new JoinClause($type, $tableExpr, $compiled['sql'], $compiled['bindings']);
        $this->bindings = array_merge($this->bindings, $compiled['bindings']);

        return $this;
    }

    /**
     * Add an INNER JOIN.
     */
    public function innerJoin(string $table, string $alias, callable $onCallback): self
    {
        return $this->join($table, $alias, 'INNER', $onCallback);
    }

    /**
     * Add a LEFT JOIN.
     */
    public function leftJoin(string $table, string $alias, callable $onCallback): self
    {
        return $this->join($table, $alias, 'LEFT', $onCallback);
    }

    #[Override]
    public function select(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    #[Override]
    public function where(string $column, mixed $value): self
    {
        $expr = $this->exprCompiler->compare($this->qualifyColumn($column), '=', $value);
        $this->wheres[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function whereOp(string $column, string $operator, mixed $value): self
    {
        $expr = $this->exprCompiler->compare($this->qualifyColumn($column), $operator, $value);
        $this->wheres[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function whereNull(string $column): self
    {
        $this->wheres[] = $this->exprCompiler->isNull($this->qualifyColumn($column));

        return $this;
    }

    #[Override]
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = $this->exprCompiler->isNull($this->qualifyColumn($column), true);

        return $this;
    }

    #[Override]
    public function whereIn(string $column, array $values): self
    {
        $expr = $this->exprCompiler->in($this->qualifyColumn($column), $values);
        $this->wheres[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function whereNotIn(string $column, array $values): self
    {
        $expr = $this->exprCompiler->in($this->qualifyColumn($column), $values, true);
        $this->wheres[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function whereBetween(string $column, mixed $low, mixed $high): self
    {
        $expr = $this->exprCompiler->between($this->qualifyColumn($column), $low, $high);
        $this->wheres[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function whereLike(string $column, LikePattern $pattern): self
    {
        $expr = $this->exprCompiler->like($this->qualifyColumn($column), $pattern);
        $this->wheres[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function whereRaw(RawExpression $expression): self
    {
        $expr = $this->exprCompiler->raw($expression);
        $this->wheres[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function orderBy(string $column, SortDirection $direction = SortDirection::Asc): self
    {
        $this->orderBys[] = sprintf('%s %s', $this->quoter->quote($this->qualifyColumn($column)), $direction->value);

        return $this;
    }

    #[Override]
    public function limit(int $limit): self
    {
        $this->limitValue = $limit;

        return $this;
    }

    #[Override]
    public function offset(int $offset): self
    {
        $this->offsetValue = $offset;

        return $this;
    }

    #[Override]
    public function groupBy(string $column): self
    {
        $this->groupBys[] = $this->quoter->quote($this->qualifyColumn($column));

        return $this;
    }

    #[Override]
    public function having(RawExpression $expression): self
    {
        $expr = $this->exprCompiler->raw($expression);
        $this->havings[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function lock(LockMode $mode): self
    {
        $this->lockMode = $mode;

        return $this;
    }

    #[Override]
    public function withFetchPlan(FetchPlan $fetchPlan): self
    {
        $this->fetchPlan = $fetchPlan;

        return $this;
    }

    #[Override]
    public function withTrashed(): self
    {
        $this->includeTrashed = true;

        return $this;
    }

    #[Override]
    public function onlyTrashed(): self
    {
        $this->onlyTrashed = true;

        return $this;
    }

    #[Override]
    public function get(): Result
    {
        $compiled = $this->toSql();

        return $this->connection->query($compiled['sql'], $compiled['bindings']);
    }

    #[Override]
    public function first(): ?Row
    {
        $prev = $this->limitValue;
        $this->limitValue = 1;
        $result = $this->get();
        $this->limitValue = $prev;

        return $result->first();
    }

    /**
     * Get the current fetch plan, if set.
     */
    public function getFetchPlan(): ?FetchPlan
    {
        return $this->fetchPlan;
    }

    #[Override]
    public function getEntities(): array
    {
        if ($this->hydrator === null || $this->entityClass === null) {
            throw QueryBuilderException::invalid('Query builder not configured for entity hydration');
        }

        $result = $this->get();

        return $this->hydrator->hydrateAll($this->entityClass, $result->rows);
    }

    #[Override]
    public function firstEntity(): ?object
    {
        if ($this->hydrator === null || $this->entityClass === null) {
            throw QueryBuilderException::invalid('Query builder not configured for entity hydration');
        }

        $row = $this->first();
        if ($row === null) {
            return null;
        }

        return $this->hydrator->hydrate($this->entityClass, $row);
    }

    #[Override]
    public function aggregate(): AggregateBuilder
    {
        $compiledWheres = [];
        foreach ($this->wheres as $expr) {
            $compiledWheres[] = $expr->sql;
        }

        $compiledJoins = [];
        foreach ($this->joins as $join) {
            $compiledJoins[] = $join->toSql();
        }

        return new AggregateBuilder(
            $this->connection,
            $this->quoter->quote(...),
            $this->compileFrom(),
            $compiledJoins,
            $compiledWheres,
            $this->bindings,
        );
    }

    #[Override]
    public function toSql(): array
    {
        $columns = $this->compileColumns();
        $from = $this->compileFrom();

        $joins = [];
        foreach ($this->joins as $join) {
            $joins[] = $join->toSql();
        }

        $wheres = $this->compileSoftDeleteFilters();
        foreach ($this->wheres as $expr) {
            $wheres[] = $expr->sql;
        }

        $havingsSql = [];
        foreach ($this->havings as $expr) {
            $havingsSql[] = $expr->sql;
        }

        $compiler = new SqlCompiler($this->connection->driver());
        $sql = $compiler->compileSelect(
            columns: $columns,
            from: $from,
            joins: $joins,
            wheres: $wheres,
            groupBy: $this->groupBys,
            havings: $havingsSql,
            orderBy: $this->orderBys,
            limit: $this->limitValue,
            offset: $this->offsetValue,
            lock: $this->lockMode,
        );

        return ['sql' => $sql, 'bindings' => $this->bindings];
    }

    /**
     * @return list<string>
     */
    private function compileColumns(): array
    {
        $result = [];
        foreach ($this->columns as $col) {
            if ($col instanceof RawExpression) {
                $result[] = $col->sql;
            } elseif ($col === '*') {
                $result[] = sprintf('%s.*', $this->quoter->quote($this->baseAlias));
            } else {
                $result[] = $this->quoter->quote($this->qualifyColumn($col));
            }
        }

        return $result;
    }

    private function compileFrom(): string
    {
        if ($this->baseAlias !== $this->baseTable) {
            return sprintf(
                '%s AS %s',
                $this->quoter->quote($this->baseTable),
                $this->quoter->quote($this->baseAlias),
            );
        }

        return $this->quoter->quote($this->baseTable);
    }

    /**
     * @return list<string>
     */
    private function compileSoftDeleteFilters(): array
    {
        if ($this->metadata === null || !$this->metadata->hasSoftDelete) {
            return [];
        }

        if ($this->includeTrashed) {
            return [];
        }

        $col = $this->quoter->quote(
            $this->baseAlias . '.' . $this->metadata->softDeleteColumn,
        );

        if ($this->onlyTrashed) {
            return [$col . ' IS NOT NULL'];
        }

        return [$col . ' IS NULL'];
    }

    /**
     * Qualify a column name with the base alias if not already qualified.
     */
    private function qualifyColumn(string $column): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $this->baseAlias . '.' . $column;
    }
}
