<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\EntityQueryBuilderInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\AggregateBuilder;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Domain\IdentifierValidator;
use Pulsar\Extension\Orm\Domain\LikePattern;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Domain\SortDirection;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;
use Pulsar\Extension\Orm\Features\Encryption\EncryptedColumnGuard;
use Pulsar\Extension\Orm\Internal\Compiler\SqlCompiler;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;
use Pulsar\Pagination\CursorPaginator;
use Pulsar\Pagination\PaginationResult;

use function array_merge;
use function assert;
use function count;
use function implode;
use function max;
use function sprintf;

/**
 * Fluent read-only SELECT query builder.
 *
 * Implements both RowQueryBuilderInterface (raw rows) and
 * EntityQueryBuilderInterface (hydrated entities).
 * @api
 */
#[Api(since: '1.0.0')]
final class SelectBuilder implements EntityQueryBuilderInterface
{
    private readonly IdentifierQuoter $quoter;
    private readonly ExpressionCompiler $exprCompiler;
    private readonly BindingCounter $bindingCounter;

    /** @var list<string|RawExpression> */
    private array $columns = ['*'];

    private string $baseTable = '';
    private string $baseAlias = '';

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

    public private(set) ?FetchPlan $fetchPlan = null;
    private bool $includeTrashed = false;
    private bool $onlyTrashed = false;

    /** @var array<string, string> Map of alias => table name (for conflict detection) */
    private array $aliasMap = [];

    private ?EntityMetadata $metadata = null;
    private ?EntityHydratorInterface $hydrator = null;
    private ?EncryptedColumnGuard $encryptedColumnGuard = null;

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
        IdentifierValidator::validateQualified($table);
        IdentifierValidator::validate($alias);
        $this->baseTable = $table;
        $this->baseAlias = $alias;
        $this->aliasMap[$alias] = $table;

        return $this;
    }

    /**
     * Set the encrypted column guard for WHERE/ORDER BY validation.
     */
    public function withEncryptedColumnGuard(EncryptedColumnGuard $guard): self
    {
        $this->encryptedColumnGuard = $guard;

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

        return $this->from($metadata->qualifiedTableName());
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
        $this->guardEncryptedWhere($column);
        $expr = $this->exprCompiler->compare($this->qualifyColumn($column), '=', $value);
        $this->wheres[] = $expr;
        $this->bindings = array_merge($this->bindings, $expr->bindings);

        return $this;
    }

    #[Override]
    public function whereOp(string $column, string $operator, mixed $value): self
    {
        $this->guardEncryptedWhere($column);
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
        $this->guardEncryptedWhere($column);
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
    public function orWhere(callable $callback): self
    {
        // Capture existing wheres, then let the callback build a new group
        $previousWheres = $this->wheres;
        $previousBindings = $this->bindings;
        $this->wheres = [];
        $this->bindings = [];

        $callback($this);

        /** @var list<Expression> $orGroup */
        $orGroup = $this->wheres;
        $orBindings = $this->bindings;

        // Restore previous state
        $this->wheres = $previousWheres;
        $this->bindings = $previousBindings;

        if (count($orGroup) === 0) {
            return $this;
        }

        // Build the OR clause from the callback's conditions
        /** @var list<string> $orSqls */
        $orSqls = [];
        foreach ($orGroup as $expr) {
            $orSqls[] = $expr->sql;
        }

        /** @var string $orClause */
        $orClause = count($orSqls) === 1
            ? $orSqls[0]
            : '(' . implode(' AND ', $orSqls) . ')';

        if (count($previousWheres) > 0) {
            // Wrap previous wheres in AND group, then OR with the new group
            $prevSqls = [];
            foreach ($previousWheres as $expr) {
                $prevSqls[] = $expr->sql;
            }
            $prevClause = count($prevSqls) === 1
                ? $prevSqls[0]
                : '(' . implode(' AND ', $prevSqls) . ')';

            $combined = new Expression(
                sprintf('(%s OR %s)', $prevClause, $orClause),
                array_merge($previousBindings, $orBindings),
            );

            $this->wheres = [$combined];
            $this->bindings = array_merge($previousBindings, $orBindings);
        } else {
            // No previous wheres: just add the OR group conditions
            $this->wheres = $orGroup;
            $this->bindings = $orBindings;
        }

        return $this;
    }

    /**
     * Filter entities that have at least one related entity matching the relation.
     *
     * Uses an EXISTS subquery to check for related records. Only works
     * for HasMany, HasOne, and BelongsToMany relations.
     *
     * @param callable(self): void|null $callback Optional callback to add constraints to the subquery
     */
    public function whereHas(
        string $relationName,
        MetadataRegistryInterface $metadataRegistry,
        ?callable $callback = null,
    ): self {
        return $this->addRelationExistsClause($relationName, $metadataRegistry, $callback, false);
    }

    /**
     * Filter entities that have no related entities matching the relation.
     *
     * Uses a NOT EXISTS subquery to check for absence of related records.
     *
     * @param callable(self): void|null $callback Optional callback to add constraints to the subquery
     */
    public function whereDoesntHave(
        string $relationName,
        MetadataRegistryInterface $metadataRegistry,
        ?callable $callback = null,
    ): self {
        return $this->addRelationExistsClause($relationName, $metadataRegistry, $callback, true);
    }

    /**
     * @param callable(self): void|null $callback
     */
    private function addRelationExistsClause(
        string $relationName,
        MetadataRegistryInterface $metadataRegistry,
        ?callable $callback,
        bool $negate,
    ): self {
        if ($this->metadata === null) {
            throw QueryBuilderException::invalid('whereHas requires entity-aware query (use forEntity)');
        }

        $relation = $this->metadata->relations[$relationName] ?? null;
        if ($relation === null) {
            throw QueryBuilderException::invalid(sprintf('Unknown relation "%s"', $relationName));
        }

        $targetMetadata = $metadataRegistry->get($relation->targetEntity);
        $subBuilder = new self($this->connection);
        $subBuilder->from($targetMetadata->tableName, 'sub0');
        $subBuilder->select([RawExpression::of('1')]);

        // Build the correlation condition based on relation type
        match ($relation->type) {
            RelationType::HasOne, RelationType::HasMany => $subBuilder->whereRaw(RawExpression::of(sprintf(
                '%s.%s = %s.%s',
                $this->quoter->quote('sub0'),
                $this->quoter->quote($relation->foreignKey),
                $this->quoter->quote($this->baseAlias),
                $this->quoter->quote($this->metadata->primaryKey->columnName),
            ))),
            RelationType::BelongsTo => $subBuilder->whereRaw(RawExpression::of(sprintf(
                '%s.%s = %s.%s',
                $this->quoter->quote('sub0'),
                $this->quoter->quote($relation->localKey),
                $this->quoter->quote($this->baseAlias),
                $this->quoter->quote($relation->foreignKey),
            ))),
            RelationType::MorphMany => (function () use ($subBuilder, $relation): void {
                assert($this->metadata !== null);
                $morphBinding = $this->bindingCounter->next('morph');
                $subBuilder->whereRaw(RawExpression::of(sprintf(
                    '%s.%s = %s.%s AND %s.%s = :%s',
                    $this->quoter->quote('sub0'),
                    $this->quoter->quote($relation->morphIdColumn ?? ''),
                    $this->quoter->quote($this->baseAlias),
                    $this->quoter->quote($this->metadata->primaryKey->columnName),
                    $this->quoter->quote('sub0'),
                    $this->quoter->quote($relation->morphTypeColumn ?? ''),
                    $morphBinding,
                )));
                $this->bindings[$morphBinding] = $this->metadata->entityClass;
            })(),
            default => throw QueryBuilderException::invalid(sprintf(
                'whereHas does not support relation type "%s"',
                $relation->type->value,
            )),
        };

        if ($callback !== null) {
            $callback($subBuilder);
        }

        $subSql = $subBuilder->toSql();
        $keyword = $negate ? 'NOT EXISTS' : 'EXISTS';
        $existsExpr = new Expression(
            sprintf('%s (%s)', $keyword, $subSql['sql']),
            $subSql['bindings'],
        );
        $this->wheres[] = $existsExpr;
        $this->bindings = array_merge($this->bindings, $subSql['bindings']);

        return $this;
    }

    #[Override]
    public function orderBy(string $column, SortDirection $direction = SortDirection::Asc): self
    {
        $this->guardEncryptedOrderBy($column);
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
     * @return list<object>
     */
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

    /**
     * Execute a count + paginated query, returning a PaginationResult.
     *
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     * @return PaginationResult<Row>
     */
    public function paginate(int $page = 1, int $perPage = 15): PaginationResult
    {
        $page = max(1, $page);
        $total = $this->aggregate()->count();

        $this->limitValue = $perPage;
        $this->offsetValue = ($page - 1) * $perPage;

        $result = $this->get();

        return new PaginationResult($result->rows, $total, $perPage, $page);
    }

    /**
     * Execute a cursor-based paginated query.
     *
     * Fetches perPage + 1 rows to determine if more pages exist,
     * without requiring a COUNT query.
     *
     * @param int $perPage Items per page
     * @param string|null $cursor Opaque cursor from the previous page
     * @param string $cursorColumn Column to use for cursor ordering
     * @return CursorPaginator<Row>
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, string $cursorColumn = 'id'): CursorPaginator
    {
        if ($cursor !== null) {
            $decoded = CursorPaginator::decodeCursor($cursor);

            if ($decoded !== null) {
                $this->whereOp($decoded['column'], '>', $decoded['value']);
            }
        }

        $this->orderBy($cursorColumn);
        $this->limitValue = $perPage + 1;

        $result = $this->get();

        return new CursorPaginator($result->rows, $perPage, $cursor, $cursorColumn);
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

        if ($this->includeTrashed || $this->metadata->softDeleteColumn === null) {
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

    /**
     * Guard against filtering on an encrypted column without a blind index.
     */
    private function guardEncryptedWhere(string $column): void
    {
        if ($this->encryptedColumnGuard !== null && $this->entityClass !== null) {
            $bare = str_contains($column, '.') ? substr($column, strpos($column, '.') + 1) : $column;
            $this->encryptedColumnGuard->guardWhere($this->entityClass, $bare);
        }
    }

    /**
     * Guard against ordering by an encrypted column.
     */
    private function guardEncryptedOrderBy(string $column): void
    {
        if ($this->encryptedColumnGuard !== null && $this->entityClass !== null) {
            $bare = str_contains($column, '.') ? substr($column, strpos($column, '.') + 1) : $column;
            $this->encryptedColumnGuard->guardOrderBy($this->entityClass, $bare);
        }
    }
}
