<?php

declare(strict_types=1);

namespace Pulsar\Benchmark\Orm;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Hydration\EntityHydrator;

/**
 * Benchmarks for entity hydration and dehydration.
 *
 * Measures the cost of converting between database rows and entity objects,
 * which is a critical hot path in any ORM.
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class HydrationBench
{
    private EntityHydrator $hydrator;
    private EntityDehydrator $dehydrator;
    private Row $singleRow;

    /** @var list<Row> */
    private array $smallResultSet;

    /** @var list<Row> */
    private array $mediumResultSet;

    /** @var list<Row> */
    private array $largeResultSet;

    private BenchEntity $entity;

    public function setUp(): void
    {
        $metadata = new EntityMetadata(
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

        $registry = $this->createRegistry($metadata);
        $this->hydrator = new EntityHydrator($registry);
        $this->dehydrator = new EntityDehydrator($registry);

        $this->singleRow = new Row([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'status' => 'active',
            'score' => 95,
        ]);

        $this->smallResultSet = $this->generateRows(10);
        $this->mediumResultSet = $this->generateRows(100);
        $this->largeResultSet = $this->generateRows(1000);

        $this->entity = new BenchEntity(
            id: 1,
            name: 'John Doe',
            email: 'john@example.com',
            status: 'active',
            score: 95,
        );
    }

    /**
     * Hydrate a single entity from one row.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchHydrateSingle(): void
    {
        $this->hydrator->hydrate(BenchEntity::class, $this->singleRow);
    }

    /**
     * Hydrate 10 entities from a small result set.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchHydrateSmallResultSet(): void
    {
        $this->hydrator->hydrateAll(BenchEntity::class, $this->smallResultSet);
    }

    /**
     * Hydrate 100 entities from a medium result set.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 1 millisecond')]
    public function benchHydrateMediumResultSet(): void
    {
        $this->hydrator->hydrateAll(BenchEntity::class, $this->mediumResultSet);
    }

    /**
     * Hydrate 1000 entities from a large result set.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 10 milliseconds')]
    public function benchHydrateLargeResultSet(): void
    {
        $this->hydrator->hydrateAll(BenchEntity::class, $this->largeResultSet);
    }

    /**
     * Dehydrate an entity for INSERT.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchDehydrateForInsert(): void
    {
        $this->dehydrator->dehydrateForInsert($this->entity);
    }

    /**
     * Dehydrate an entity for UPDATE.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchDehydrateForUpdate(): void
    {
        $this->dehydrator->dehydrateForUpdate($this->entity);
    }

    /**
     * Extract primary key from entity.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 10 microseconds')]
    public function benchExtractId(): void
    {
        $this->dehydrator->extractId($this->entity);
    }

    /**
     * @return list<Row>
     */
    private function generateRows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = new Row([
                'id' => $i + 1,
                'name' => "User $i",
                'email' => "user$i@example.com",
                'status' => $i % 2 === 0 ? 'active' : 'inactive',
                'score' => $i * 10,
            ]);
        }

        return $rows;
    }

    private function createRegistry(EntityMetadata $metadata): MetadataRegistryInterface
    {
        return new class ($metadata) implements MetadataRegistryInterface {
            public function __construct(private readonly EntityMetadata $metadata) {}

            public function get(string $entityClass): EntityMetadata
            {
                return $this->metadata;
            }

            public function has(string $entityClass): bool
            {
                return true;
            }
        };
    }
}
