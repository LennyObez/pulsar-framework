<?php

declare(strict_types=1);

namespace Pulsar\Benchmark\Orm;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\LikePattern;
use Pulsar\Extension\Orm\Domain\SortDirection;
use Pulsar\Extension\Orm\Features\Query\DeleteBuilder;
use Pulsar\Extension\Orm\Features\Query\InsertBuilder;
use Pulsar\Extension\Orm\Features\Query\JoinOnBuilder;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Features\Query\UpdateBuilder;
use RuntimeException;

/**
 * Benchmarks for ORM query builder SQL generation.
 *
 * Measures the cost of building SELECT, INSERT, UPDATE, and DELETE
 * queries without executing them against a real database.
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class QueryBuilderBench
{
    private ConnectionInterface $connection;
    private EntityMetadata $metadata;
    private EntityHydratorInterface $hydrator;

    public function setUp(): void
    {
        $this->connection = new NullConnection();
        $this->hydrator = new NullHydrator();

        $this->metadata = new EntityMetadata(
            entityClass: BenchEntity::class,
            tableName: 'bench_entities',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: [
                'id' => new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
                'name' => new ColumnMetadata('name', 'name', ColumnType::String),
                'email' => new ColumnMetadata('email', 'email', ColumnType::String),
                'status' => new ColumnMetadata('status', 'status', ColumnType::String),
                'score' => new ColumnMetadata('score', 'score', ColumnType::Integer),
            ],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    /**
     * Simple SELECT * FROM table.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchSimpleSelect(): void
    {
        $builder = new SelectBuilder($this->connection);
        $builder->from('bench_entities');
        $builder->toSql();
    }

    /**
     * SELECT with WHERE, ORDER BY, LIMIT, and OFFSET.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchComplexSelect(): void
    {
        $builder = new SelectBuilder($this->connection);
        $builder->forEntity(BenchEntity::class, $this->metadata, $this->hydrator);
        $builder->where('status', 'active');
        $builder->whereOp('score', '>', 50);
        $builder->whereIn('id', [1, 2, 3, 4, 5]);
        $builder->orderBy('name', SortDirection::Asc);
        $builder->orderBy('score', SortDirection::Desc);
        $builder->limit(25);
        $builder->offset(100);
        $builder->toSql();
    }

    /**
     * SELECT with LIKE pattern.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchSelectWithLike(): void
    {
        $builder = new SelectBuilder($this->connection);
        $builder->from('bench_entities');
        $builder->whereLike('name', LikePattern::contains('test'));
        $builder->toSql();
    }

    /**
     * SELECT with JOIN.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchSelectWithJoin(): void
    {
        $builder = new SelectBuilder($this->connection);
        $builder->from('bench_entities', 't0');
        $builder->innerJoin('profiles', 'p', static fn(JoinOnBuilder $on): JoinOnBuilder => $on->on('t0.id', '=', 'p.user_id'));
        $builder->where('status', 'active');
        // toSql() answers with the statement AND its bindings, so the emptiness
        // check has to look inside rather than compare the compound to ''.
        $compiled = $builder->toSql();

        if ($compiled['sql'] === '') {
            throw new RuntimeException('SelectBuilder produced no SQL');
        }
    }

    /**
     * INSERT query building.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchInsertBuild(): void
    {
        $builder = new InsertBuilder($this->connection, 'bench_entities');
        $builder->values([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'status' => 'active',
            'score' => 100,
        ]);
        // Note: we don't call execute() since that would require a real DB connection
    }

    /**
     * UPDATE query building.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchUpdateBuild(): void
    {
        $builder = new UpdateBuilder($this->connection, 'bench_entities');
        $builder->set([
            'name' => 'Jane Doe',
            'status' => 'inactive',
            'score' => 200,
        ]);
        $builder->where('id', 1);
    }

    /**
     * DELETE query building.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchDeleteBuild(): void
    {
        $builder = new DeleteBuilder($this->connection, 'bench_entities');
        $builder->where('id', 1);
        $builder->where('status', 'deleted');
    }

    /**
     * Repeated SELECT building to test allocation pressure.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 500 microseconds')]
    public function benchTenSequentialSelectBuilds(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $builder = new SelectBuilder($this->connection);
            $builder->from('bench_entities');
            $builder->where('id', $i);
            $builder->orderBy('name');
            $builder->limit(10);
            $builder->toSql();
        }
    }
}

/**
 * @internal Benchmark-only stub entity
 */
final class BenchEntity
{
    public function __construct(
        public readonly int $id = 0,
        public readonly string $name = '',
        public readonly string $email = '',
        public readonly string $status = '',
        public readonly int $score = 0,
    ) {}
}

/**
 * @internal Benchmark-only null connection that never executes queries
 */
final class NullConnection implements ConnectionInterface
{
    public function query(string $sql, array $bindings = []): Result
    {
        return new Result([]);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return 0;
    }

    public function prepare(string $sql): Statement
    {
        throw new RuntimeException('Not implemented in benchmark stub');
    }

    public function beginTransaction(): Transaction
    {
        throw new RuntimeException('Not implemented in benchmark stub');
    }

    public function transaction(callable $callback): mixed
    {
        throw new RuntimeException('Not implemented in benchmark stub');
    }

    public function lastInsertId(): string
    {
        return '0';
    }

    public function driver(): Driver
    {
        return Driver::SQLite;
    }

    public function variant(): \Pulsar\Database\DriverVariant
    {
        return \Pulsar\Database\DriverVariant::Standard;
    }

    public function dialect(): \Pulsar\Database\Dialect\DialectInterface
    {
        return \Pulsar\Database\Dialect\Dialects::for($this->driver(), $this->variant());
    }

    public function name(): string
    {
        return 'bench';
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function disconnect(): void {}
}

/**
 * @internal Benchmark-only null hydrator
 */
final class NullHydrator implements EntityHydratorInterface
{
    /**
     * Always builds a BenchEntity, whatever class was asked for: the point is to
     * measure the query path with hydration cost removed, not to hydrate.
     *
     * The interface promises `@return T` for `class-string<T>`, and returning a
     * fixed class breaks that promise, so the requested class is asserted instead
     * of ignored — a benchmark that silently hydrated the wrong entity would report
     * a time for work it never did.
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T
     */
    public function hydrate(string $entityClass, Row $row): object
    {
        $entity = new BenchEntity();

        if (!$entity instanceof $entityClass) {
            throw new RuntimeException("NullHydrator only hydrates BenchEntity, got {$entityClass}");
        }

        return $entity;
    }

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @param list<Row> $rows
     * @return list<T>
     */
    public function hydrateAll(string $entityClass, array $rows): array
    {
        return array_map(fn(Row $row): object => $this->hydrate($entityClass, $row), $rows);
    }
}
