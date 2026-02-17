<?php

declare(strict_types=1);

namespace Pulsar\Benchmark\Orm;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Features\Relation\BatchLoader;
use Pulsar\Extension\Orm\Features\Relation\RelationLoader;
use Pulsar\Extension\Orm\Features\Relation\WithCountLoader;

/**
 * Benchmarks for ORM relation loading strategies.
 *
 * Compares eager loading (batch IN queries) vs relation count loading,
 * measuring overhead of the relation resolution infrastructure.
 */
#[BeforeMethods('setUp')]
#[Revs(100)]
#[Iterations(5)]
#[Warmup(1)]
final class RelationBench
{
    private RelationLoader $loader;
    private WithCountLoader $countLoader;
    private BatchLoader $batchLoader;

    /** @var list<BenchUser> */
    private array $users;

    private EntityMetadata $userMetadata;
    private EntityMetadata $postMetadata;

    public function setUp(): void
    {
        $hasManyRelation = new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: BenchPost::class,
            foreignKey: 'author_id',
            localKey: 'id',
        );

        $this->userMetadata = new EntityMetadata(
            entityClass: BenchUser::class,
            tableName: 'users',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: [
                'id' => new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
                'name' => new ColumnMetadata('name', 'name', ColumnType::String),
            ],
            relations: ['posts' => $hasManyRelation],
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

        $this->postMetadata = new EntityMetadata(
            entityClass: BenchPost::class,
            tableName: 'posts',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: [
                'id' => new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
                'author_id' => new ColumnMetadata('author_id', 'author_id', ColumnType::Integer),
                'title' => new ColumnMetadata('title', 'title', ColumnType::String),
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

        $registry = $this->createRegistry();
        $hydrator = $this->createHydrator();

        // Generate post rows that the connection will return
        $postRows = [];
        for ($i = 0; $i < 500; $i++) {
            $postRows[] = new Row([
                'id' => $i + 1,
                'author_id' => ($i % 50) + 1,
                'title' => "Post $i",
            ]);
        }

        $connection = new PreloadedConnection(new Result($postRows));

        $this->loader = new RelationLoader($connection, $registry, $hydrator);
        $this->countLoader = new WithCountLoader($connection, $registry);
        $this->batchLoader = new BatchLoader($connection, $registry, $hydrator);

        // Generate parent user entities
        $this->users = [];
        for ($i = 0; $i < 50; $i++) {
            $this->users[] = new BenchUser($i + 1, "User $i");
        }
    }

    /**
     * Eager-load HasMany relations for 50 parent entities (500 children).
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 10 milliseconds')]
    public function benchEagerLoadHasMany(): void
    {
        // Reset posts to trigger relation loading
        foreach ($this->users as $user) {
            $user->posts = [];
        }
        $this->loader->loadRelations($this->users, FetchPlan::with(['posts']));
    }

    /**
     * Load relation counts for 50 parent entities.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 milliseconds')]
    public function benchWithCount(): void
    {
        $this->countLoader->loadCounts($this->users, ['posts']);
    }

    /**
     * Batch-load entities by IDs (500 IDs, batch size 100).
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 10 milliseconds')]
    public function benchBatchLoadByIds(): void
    {
        $ids = range(1, 500);
        $this->batchLoader->loadByIds(BenchPost::class, 'id', $ids);
    }

    /**
     * FetchPlan creation and merging.
     */
    #[Subject]
    #[Revs(1000)]
    #[Assert('mode(variant.time.avg) < 10 microseconds')]
    public function benchFetchPlanCreation(): void
    {
        $plan1 = FetchPlan::with(['posts', 'comments', 'tags']);
        $plan2 = FetchPlan::with(['author', 'category']);
        $plan1->merge($plan2);
    }

    /**
     * Nested FetchPlan creation.
     */
    #[Subject]
    #[Revs(1000)]
    #[Assert('mode(variant.time.avg) < 10 microseconds')]
    public function benchNestedFetchPlan(): void
    {
        FetchPlan::withNested([
            'posts' => FetchPlan::with(['comments', 'tags']),
            'profile' => null,
        ]);
    }

    /**
     * Empty relation load (no-op path).
     */
    #[Subject]
    #[Revs(1000)]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchEmptyRelationLoad(): void
    {
        $this->loader->loadRelations([], FetchPlan::with(['posts']));
    }

    /**
     * Empty fetch plan (no-op path).
     */
    #[Subject]
    #[Revs(1000)]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchNoOpFetchPlan(): void
    {
        $this->loader->loadRelations($this->users, FetchPlan::none());
    }

    private function createRegistry(): MetadataRegistryInterface
    {
        $userMeta = $this->userMetadata;
        $postMeta = $this->postMetadata;

        return new class ($userMeta, $postMeta) implements MetadataRegistryInterface {
            public function __construct(
                private readonly EntityMetadata $userMeta,
                private readonly EntityMetadata $postMeta,
            ) {}

            public function get(string $entityClass): EntityMetadata
            {
                return match ($entityClass) {
                    BenchUser::class => $this->userMeta,
                    BenchPost::class => $this->postMeta,
                    default => throw new \RuntimeException("Unknown: $entityClass"),
                };
            }

            public function has(string $entityClass): bool
            {
                return $entityClass === BenchUser::class || $entityClass === BenchPost::class;
            }
        };
    }

    private function createHydrator(): EntityHydratorInterface
    {
        return new class implements EntityHydratorInterface {
            public function hydrate(string $entityClass, Row $row): object
            {
                return match ($entityClass) {
                    BenchPost::class => new BenchPost(
                        $row->getInt('id'),
                        $row->getInt('author_id'),
                        $row->getString('title'),
                    ),
                    BenchUser::class => new BenchUser(
                        $row->getInt('id'),
                        $row->getString('name'),
                    ),
                    default => throw new \RuntimeException("Unknown: $entityClass"),
                };
            }

            public function hydrateAll(string $entityClass, array $rows): array
            {
                return array_map(fn(Row $r) => $this->hydrate($entityClass, $r), $rows);
            }
        };
    }
}

/**
 * @internal Benchmark-only user entity
 */
final class BenchUser
{
    /** @var list<BenchPost> */
    public array $posts = [];

    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}

/**
 * @internal Benchmark-only post entity
 */
final class BenchPost
{
    public function __construct(
        public readonly int $id,
        public readonly int $author_id,
        public readonly string $title,
    ) {}
}

/**
 * @internal Connection that always returns pre-loaded results
 */
final class PreloadedConnection implements \Pulsar\Database\ConnectionInterface
{
    public function __construct(private readonly Result $result) {}

    public function query(string $sql, array $bindings = []): Result
    {
        return $this->result;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return 0;
    }

    public function prepare(string $sql): \Pulsar\Database\Statement
    {
        throw new \RuntimeException('Not implemented');
    }

    public function beginTransaction(): \Pulsar\Database\Transaction
    {
        throw new \RuntimeException('Not implemented');
    }

    public function transaction(callable $callback): mixed
    {
        throw new \RuntimeException('Not implemented');
    }

    public function lastInsertId(): string
    {
        return '0';
    }

    public function driver(): \Pulsar\Database\Driver
    {
        return \Pulsar\Database\Driver::SQLite;
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
