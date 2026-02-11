<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Relation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Relation\BatchLoader;

#[CoversClass(BatchLoader::class)]
final class BatchLoaderTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private MetadataRegistryInterface&Stub $metadataRegistry;
    private EntityHydratorInterface&Stub $hydrator;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);

        $this->metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $this->hydrator = $this->createStub(EntityHydratorInterface::class);
    }

    #[Test]
    public function loadByIdsReturnsEmptyForEmptyIds(): void
    {
        $loader = new BatchLoader(
            $this->connection,
            $this->metadataRegistry,
            $this->hydrator,
        );

        $result = $loader->loadByIds(BatchTestEntity::class, 'id', []);

        self::assertSame([], $result);
    }

    #[Test]
    public function loadByIdsDoesNotQueryForEmptyIds(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::never())->method('query');

        $loader = new BatchLoader(
            $connection,
            $this->metadataRegistry,
            $this->hydrator,
        );

        $loader->loadByIds(BatchTestEntity::class, 'id', []);
    }

    #[Test]
    public function loadByIdsSingleBatch(): void
    {
        $metadata = $this->buildMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $entity1 = new BatchTestEntity();
        $entity1->id = 1;
        $entity1->title = 'A';

        $entity2 = new BatchTestEntity();
        $entity2->id = 2;
        $entity2->title = 'B';

        $result = new Result([
            new Row(['id' => 1, 'title' => 'A']),
            new Row(['id' => 2, 'title' => 'B']),
        ]);

        $this->connection->method('query')->willReturn($result);
        $this->hydrator->method('hydrateAll')->willReturn([$entity1, $entity2]);

        $loader = new BatchLoader(
            $this->connection,
            $this->metadataRegistry,
            $this->hydrator,
            batchSize: 500,
        );

        $entities = $loader->loadByIds(BatchTestEntity::class, 'id', [1, 2]);

        self::assertCount(2, $entities);
    }

    #[Test]
    public function loadByIdsChunksLargeIdSets(): void
    {
        $metadata = $this->buildMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $emptyResult = new Result([]);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        // With batch size 2 and 5 IDs, we expect 3 queries (2+2+1)
        $connection->expects(self::exactly(3))->method('query')->willReturn($emptyResult);
        $this->hydrator->method('hydrateAll')->willReturn([]);

        $loader = new BatchLoader(
            $connection,
            $this->metadataRegistry,
            $this->hydrator,
            batchSize: 2,
        );

        $entities = $loader->loadByIds(BatchTestEntity::class, 'id', [1, 2, 3, 4, 5]);

        self::assertSame([], $entities);
    }

    #[Test]
    public function loadByIdsMergesResultsAcrossBatches(): void
    {
        $metadata = $this->buildMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $entity1 = new BatchTestEntity();
        $entity1->id = 1;
        $entity1->title = 'First';

        $entity2 = new BatchTestEntity();
        $entity2->id = 2;
        $entity2->title = 'Second';

        $entity3 = new BatchTestEntity();
        $entity3->id = 3;
        $entity3->title = 'Third';

        $result1 = new Result([
            new Row(['id' => 1, 'title' => 'First']),
            new Row(['id' => 2, 'title' => 'Second']),
        ]);

        $result2 = new Result([
            new Row(['id' => 3, 'title' => 'Third']),
        ]);

        $queryCallCount = 0;
        $this->connection->method('query')->willReturnCallback(
            function () use (&$queryCallCount, $result1, $result2): Result {
                $queryCallCount++;

                return $queryCallCount === 1 ? $result1 : $result2;
            },
        );

        $hydrateCallCount = 0;
        $this->hydrator->method('hydrateAll')->willReturnCallback(
            function () use (&$hydrateCallCount, $entity1, $entity2, $entity3): array {
                $hydrateCallCount++;

                return $hydrateCallCount === 1 ? [$entity1, $entity2] : [$entity3];
            },
        );

        $loader = new BatchLoader(
            $this->connection,
            $this->metadataRegistry,
            $this->hydrator,
            batchSize: 2,
        );

        $entities = $loader->loadByIds(BatchTestEntity::class, 'id', [1, 2, 3]);

        self::assertCount(3, $entities);
        self::assertInstanceOf(BatchTestEntity::class, $entities[0]);
        self::assertInstanceOf(BatchTestEntity::class, $entities[1]);
        self::assertInstanceOf(BatchTestEntity::class, $entities[2]);
        self::assertSame('First', $entities[0]->title);
        self::assertSame('Second', $entities[1]->title);
        self::assertSame('Third', $entities[2]->title);
    }

    private function buildMetadata(): EntityMetadata
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
            autoIncrement: true,
        );

        $titleCol = new ColumnMetadata(
            propertyName: 'title',
            columnName: 'title',
            type: ColumnType::String,
        );

        return new EntityMetadata(
            entityClass: BatchTestEntity::class,
            tableName: 'posts',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'title' => $titleCol],
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
}

class BatchTestEntity
{
    public int $id;
    public string $title = '';
}
