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
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Features\Relation\RelationLoader;
use RuntimeException;

#[CoversClass(RelationLoader::class)]
final class RelationLoaderTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private EntityHydratorInterface&Stub $hydrator;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);

        $this->hydrator = $this->createStub(EntityHydratorInterface::class);
    }

    #[Test]
    public function loadRelationsReturnsEarlyForEmptyEntities(): void
    {
        $metadataRegistry = $this->createMock(MetadataRegistryInterface::class);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $fetchPlan = FetchPlan::with(['posts']);

        // Should not call metadataRegistry->get() for empty entities
        $metadataRegistry->expects(self::never())->method('get');

        $loader->loadRelations([], $fetchPlan);
    }

    #[Test]
    public function loadRelationsReturnsEarlyForEmptyFetchPlan(): void
    {
        $metadataRegistry = $this->createMock(MetadataRegistryInterface::class);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUser();
        $entity->id = 1;
        $entity->name = 'Test';

        $fetchPlan = FetchPlan::none();

        // Should not call metadataRegistry->get() for empty fetch plan
        $metadataRegistry->expects(self::never())->method('get');

        $loader->loadRelations([$entity], $fetchPlan);
    }

    #[Test]
    public function loadRelationsSkipsUnknownRelations(): void
    {
        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadata = $this->buildUserMetadataWithoutRelations();
        $metadataRegistry->method('get')->willReturn($metadata);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $loader = new RelationLoader(
            $connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUser();
        $entity->id = 1;
        $entity->name = 'Test';

        // 'nonexistent' relation doesn't exist in metadata
        $fetchPlan = FetchPlan::with(['nonexistent']);

        // Should not query database for unknown relations
        $connection->expects(self::never())->method('query');

        $loader->loadRelations([$entity], $fetchPlan);
    }

    #[Test]
    public function loadRelationsHandlesBelongsToRelation(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'department',
            type: RelationType::BelongsTo,
            targetEntity: RelationTestDept::class,
            foreignKey: 'deptId',
            localKey: 'id',
        );

        $userMetadata = $this->buildUserMetadataWithRelation($relation);
        $deptMetadata = $this->buildDeptMetadata();

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithDept::class => $userMetadata,
                RelationTestDept::class => $deptMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        $emptyResult = new Result([]);
        $this->connection->method('query')->willReturn($emptyResult);
        $this->hydrator->method('hydrateAll')->willReturn([]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUserWithDept();
        $entity->id = 1;
        $entity->name = 'Test';
        $entity->deptId = 5;

        $fetchPlan = FetchPlan::with(['department']);
        $loader->loadRelations([$entity], $fetchPlan);

        // No related entity found, so department should be null
        self::assertNull($entity->department);
    }

    #[Test]
    public function loadRelationsHandlesBelongsToManyWithMissingPivotConfig(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'tags',
            type: RelationType::BelongsToMany,
            targetEntity: RelationTestTag::class,
            foreignKey: 'user_id',
            localKey: 'id',
            pivotTable: null,
            pivotForeignKey: null,
            pivotRelatedKey: null,
        );

        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
        );

        $metadata = new EntityMetadata(
            entityClass: RelationTestUserWithTags::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
            relations: ['tags' => $relation],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')->willReturn($metadata);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $loader = new RelationLoader(
            $connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUserWithTags();
        $entity->id = 1;
        $entity->name = 'Test';

        // Should not query DB when pivot config is missing
        $connection->expects(self::never())->method('query');

        $fetchPlan = FetchPlan::with(['tags']);
        $loader->loadRelations([$entity], $fetchPlan);
    }

    private function buildUserMetadataWithoutRelations(): EntityMetadata
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
        );

        return new EntityMetadata(
            entityClass: RelationTestUser::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
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

    private function buildUserMetadataWithRelation(RelationMetadata $relation): EntityMetadata
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );

        $deptIdCol = new ColumnMetadata(
            propertyName: 'deptId',
            columnName: 'dept_id',
            type: ColumnType::Integer,
            nullable: true,
        );

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
        );

        return new EntityMetadata(
            entityClass: RelationTestUserWithDept::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol, 'deptId' => $deptIdCol],
            relations: ['department' => $relation],
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

    private function buildDeptMetadata(): EntityMetadata
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );

        $labelCol = new ColumnMetadata(
            propertyName: 'label',
            columnName: 'label',
            type: ColumnType::String,
        );

        return new EntityMetadata(
            entityClass: RelationTestDept::class,
            tableName: 'departments',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'label' => $labelCol],
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

class RelationTestUser
{
    public int $id;
    public string $name = '';
}

class RelationTestUserWithDept
{
    public int $id;
    public string $name = '';
    public ?int $deptId = null;
    public ?object $department = null;
}

class RelationTestDept
{
    public int $id;
    public string $label = '';
}

class RelationTestTag
{
    public int $id;
    public string $label = '';
}

class RelationTestUserWithTags
{
    public int $id;
    public string $name = '';
    /** @var list<object> */
    public array $tags = [];
}
