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

    #[Test]
    public function loadBelongsToAssignsMatchedRelatedEntities(): void
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

        $dept = new RelationTestDept();
        $dept->id = 5;
        $dept->label = 'Engineering';

        $emptyResult = new Result([]);
        $this->connection->method('query')->willReturn($emptyResult);
        $this->hydrator->method('hydrateAll')->willReturn([$dept]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUserWithDept();
        $entity->id = 1;
        $entity->name = 'Alice';
        $entity->deptId = 5;

        $fetchPlan = FetchPlan::with(['department']);
        $loader->loadRelations([$entity], $fetchPlan);

        self::assertNotNull($entity->department);
        self::assertSame($dept, $entity->department);
    }

    #[Test]
    public function loadBelongsToHandlesMultipleEntitiesWithSameForeignKey(): void
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

        $dept = new RelationTestDept();
        $dept->id = 5;
        $dept->label = 'Engineering';

        $this->connection->method('query')->willReturn(new Result([]));
        $this->hydrator->method('hydrateAll')->willReturn([$dept]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity1 = new RelationTestUserWithDept();
        $entity1->id = 1;
        $entity1->name = 'Alice';
        $entity1->deptId = 5;

        $entity2 = new RelationTestUserWithDept();
        $entity2->id = 2;
        $entity2->name = 'Bob';
        $entity2->deptId = 5;

        $fetchPlan = FetchPlan::with(['department']);
        $loader->loadRelations([$entity1, $entity2], $fetchPlan);

        self::assertSame($dept, $entity1->department);
        self::assertSame($dept, $entity2->department);
    }

    #[Test]
    public function loadBelongsToWithNestedFetchPlanLoadsNestedRelations(): void
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

        $dept = new RelationTestDept();
        $dept->id = 5;
        $dept->label = 'Engineering';

        $this->connection->method('query')->willReturn(new Result([]));
        $this->hydrator->method('hydrateAll')->willReturn([$dept]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUserWithDept();
        $entity->id = 1;
        $entity->name = 'Alice';
        $entity->deptId = 5;

        // Nested plan will attempt to load further nested relations on the dept
        // but since dept has no relations, it won't do anything beyond assignment
        $nestedPlan = FetchPlan::with(['manager']);
        $fetchPlan = FetchPlan::withNested(['department' => $nestedPlan]);
        $loader->loadRelations([$entity], $fetchPlan);

        self::assertSame($dept, $entity->department);
    }

    #[Test]
    public function loadHasOneAssignsRelatedEntityByForeignKey(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'profile',
            type: RelationType::HasOne,
            targetEntity: RelationTestProfile::class,
            foreignKey: 'user_id',
            localKey: 'id',
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

        $userMetadata = new EntityMetadata(
            entityClass: RelationTestUserWithProfile::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
            relations: ['profile' => $relation],
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

        $profilePk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );
        $userIdCol = new ColumnMetadata(
            propertyName: 'userId',
            columnName: 'user_id',
            type: ColumnType::Integer,
        );
        $bioCol = new ColumnMetadata(
            propertyName: 'bio',
            columnName: 'bio',
            type: ColumnType::String,
        );

        $profileMetadata = new EntityMetadata(
            entityClass: RelationTestProfile::class,
            tableName: 'profiles',
            schema: null,
            primaryKey: $profilePk,
            columns: ['id' => $profilePk, 'userId' => $userIdCol, 'bio' => $bioCol],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithProfile::class => $userMetadata,
                RelationTestProfile::class => $profileMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        $profile = new RelationTestProfile();
        $profile->id = 10;
        $profile->userId = 1;
        $profile->bio = 'Hello World';

        $this->connection->method('query')->willReturn(new Result([]));
        $this->hydrator->method('hydrateAll')->willReturn([$profile]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUserWithProfile();
        $entity->id = 1;
        $entity->name = 'Alice';

        $fetchPlan = FetchPlan::with(['profile']);
        $loader->loadRelations([$entity], $fetchPlan);

        self::assertNotNull($entity->profile);
        self::assertSame($profile, $entity->profile);
    }

    #[Test]
    public function loadHasOneAssignsNullWhenNoRelatedEntityFound(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'profile',
            type: RelationType::HasOne,
            targetEntity: RelationTestProfile::class,
            foreignKey: 'user_id',
            localKey: 'id',
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

        $userMetadata = new EntityMetadata(
            entityClass: RelationTestUserWithProfile::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
            relations: ['profile' => $relation],
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

        $profilePk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );
        $userIdCol = new ColumnMetadata(
            propertyName: 'userId',
            columnName: 'user_id',
            type: ColumnType::Integer,
        );
        $bioCol = new ColumnMetadata(
            propertyName: 'bio',
            columnName: 'bio',
            type: ColumnType::String,
        );

        $profileMetadata = new EntityMetadata(
            entityClass: RelationTestProfile::class,
            tableName: 'profiles',
            schema: null,
            primaryKey: $profilePk,
            columns: ['id' => $profilePk, 'userId' => $userIdCol, 'bio' => $bioCol],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithProfile::class => $userMetadata,
                RelationTestProfile::class => $profileMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        $this->connection->method('query')->willReturn(new Result([]));
        $this->hydrator->method('hydrateAll')->willReturn([]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUserWithProfile();
        $entity->id = 1;
        $entity->name = 'Alice';

        $fetchPlan = FetchPlan::with(['profile']);
        $loader->loadRelations([$entity], $fetchPlan);

        self::assertNull($entity->profile);
    }

    #[Test]
    public function loadHasManyGroupsRelatedEntitiesByForeignKey(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: RelationTestPost::class,
            foreignKey: 'user_id',
            localKey: 'id',
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

        $userMetadata = new EntityMetadata(
            entityClass: RelationTestUserWithPosts::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
            relations: ['posts' => $relation],
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

        $postPk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );
        $postUserIdCol = new ColumnMetadata(
            propertyName: 'userId',
            columnName: 'user_id',
            type: ColumnType::Integer,
        );
        $titleCol = new ColumnMetadata(
            propertyName: 'title',
            columnName: 'title',
            type: ColumnType::String,
        );

        $postMetadata = new EntityMetadata(
            entityClass: RelationTestPost::class,
            tableName: 'posts',
            schema: null,
            primaryKey: $postPk,
            columns: ['id' => $postPk, 'userId' => $postUserIdCol, 'title' => $titleCol],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithPosts::class => $userMetadata,
                RelationTestPost::class => $postMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        $post1 = new RelationTestPost();
        $post1->id = 100;
        $post1->userId = 1;
        $post1->title = 'Post A';

        $post2 = new RelationTestPost();
        $post2->id = 101;
        $post2->userId = 1;
        $post2->title = 'Post B';

        $post3 = new RelationTestPost();
        $post3->id = 102;
        $post3->userId = 2;
        $post3->title = 'Post C';

        $this->connection->method('query')->willReturn(new Result([]));
        $this->hydrator->method('hydrateAll')->willReturn([$post1, $post2, $post3]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $user1 = new RelationTestUserWithPosts();
        $user1->id = 1;
        $user1->name = 'Alice';

        $user2 = new RelationTestUserWithPosts();
        $user2->id = 2;
        $user2->name = 'Bob';

        $fetchPlan = FetchPlan::with(['posts']);
        $loader->loadRelations([$user1, $user2], $fetchPlan);

        self::assertCount(2, $user1->posts);
        self::assertSame($post1, $user1->posts[0]);
        self::assertSame($post2, $user1->posts[1]);

        self::assertCount(1, $user2->posts);
        self::assertSame($post3, $user2->posts[0]);
    }

    #[Test]
    public function loadHasManyAssignsEmptyArrayWhenNoRelatedEntities(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: RelationTestPost::class,
            foreignKey: 'user_id',
            localKey: 'id',
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

        $userMetadata = new EntityMetadata(
            entityClass: RelationTestUserWithPosts::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
            relations: ['posts' => $relation],
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

        $postPk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );
        $postUserIdCol = new ColumnMetadata(
            propertyName: 'userId',
            columnName: 'user_id',
            type: ColumnType::Integer,
        );
        $titleCol = new ColumnMetadata(
            propertyName: 'title',
            columnName: 'title',
            type: ColumnType::String,
        );

        $postMetadata = new EntityMetadata(
            entityClass: RelationTestPost::class,
            tableName: 'posts',
            schema: null,
            primaryKey: $postPk,
            columns: ['id' => $postPk, 'userId' => $postUserIdCol, 'title' => $titleCol],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithPosts::class => $userMetadata,
                RelationTestPost::class => $postMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        $this->connection->method('query')->willReturn(new Result([]));
        $this->hydrator->method('hydrateAll')->willReturn([]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $user = new RelationTestUserWithPosts();
        $user->id = 1;
        $user->name = 'Alice';

        $fetchPlan = FetchPlan::with(['posts']);
        $loader->loadRelations([$user], $fetchPlan);

        self::assertSame([], $user->posts);
    }

    #[Test]
    public function loadBelongsToManyWithPivotTableLoadsRelatedEntities(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'tags',
            type: RelationType::BelongsToMany,
            targetEntity: RelationTestTag::class,
            foreignKey: 'user_id',
            localKey: 'id',
            pivotTable: 'user_tags',
            pivotForeignKey: 'user_id',
            pivotRelatedKey: 'tag_id',
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

        $userMetadata = new EntityMetadata(
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

        $tagPk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );
        $tagLabelCol = new ColumnMetadata(
            propertyName: 'label',
            columnName: 'label',
            type: ColumnType::String,
        );

        $tagMetadata = new EntityMetadata(
            entityClass: RelationTestTag::class,
            tableName: 'tags',
            schema: null,
            primaryKey: $tagPk,
            columns: ['id' => $tagPk, 'label' => $tagLabelCol],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithTags::class => $userMetadata,
                RelationTestTag::class => $tagMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        // Pivot query returns rows
        $pivotRows = new Result([
            new Row(['user_id' => 1, 'tag_id' => 10]),
            new Row(['user_id' => 1, 'tag_id' => 20]),
        ]);

        $tag1 = new RelationTestTag();
        $tag1->id = 10;
        $tag1->label = 'PHP';

        $tag2 = new RelationTestTag();
        $tag2->id = 20;
        $tag2->label = 'ORM';

        // First query() call is the pivot table, second is the entity query
        $callCount = 0;
        $this->connection->method('query')
            ->willReturnCallback(function () use (&$callCount, $pivotRows): Result {
                $callCount++;
                if ($callCount === 1) {
                    return $pivotRows;
                }

                return new Result([]);
            });

        $this->hydrator->method('hydrateAll')->willReturn([$tag1, $tag2]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $user = new RelationTestUserWithTags();
        $user->id = 1;
        $user->name = 'Alice';

        $fetchPlan = FetchPlan::with(['tags']);
        $loader->loadRelations([$user], $fetchPlan);

        self::assertCount(2, $user->tags);
        self::assertSame($tag1, $user->tags[0]);
        self::assertSame($tag2, $user->tags[1]);
    }

    #[Test]
    public function loadBelongsToManyAssignsEmptyArrayWhenPivotReturnsNoRelatedIds(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'tags',
            type: RelationType::BelongsToMany,
            targetEntity: RelationTestTag::class,
            foreignKey: 'user_id',
            localKey: 'id',
            pivotTable: 'user_tags',
            pivotForeignKey: 'user_id',
            pivotRelatedKey: 'tag_id',
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

        $userMetadata = new EntityMetadata(
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
        $metadataRegistry->method('get')->willReturn($userMetadata);

        // Pivot query returns no rows
        $this->connection->method('query')->willReturn(new Result([]));

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $user = new RelationTestUserWithTags();
        $user->id = 1;
        $user->name = 'Alice';

        $fetchPlan = FetchPlan::with(['tags']);
        $loader->loadRelations([$user], $fetchPlan);

        self::assertSame([], $user->tags);
    }

    #[Test]
    public function loadHasOneWithNestedFetchPlanTriggersNestedLoading(): void
    {
        $profileRelation = new RelationMetadata(
            propertyName: 'profile',
            type: RelationType::HasOne,
            targetEntity: RelationTestProfile::class,
            foreignKey: 'user_id',
            localKey: 'id',
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

        $userMetadata = new EntityMetadata(
            entityClass: RelationTestUserWithProfile::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
            relations: ['profile' => $profileRelation],
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

        $profilePk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );
        $userIdCol = new ColumnMetadata(
            propertyName: 'userId',
            columnName: 'user_id',
            type: ColumnType::Integer,
        );
        $bioCol = new ColumnMetadata(
            propertyName: 'bio',
            columnName: 'bio',
            type: ColumnType::String,
        );

        $profileMetadata = new EntityMetadata(
            entityClass: RelationTestProfile::class,
            tableName: 'profiles',
            schema: null,
            primaryKey: $profilePk,
            columns: ['id' => $profilePk, 'userId' => $userIdCol, 'bio' => $bioCol],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithProfile::class => $userMetadata,
                RelationTestProfile::class => $profileMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        $profile = new RelationTestProfile();
        $profile->id = 10;
        $profile->userId = 1;
        $profile->bio = 'Hello';

        $this->connection->method('query')->willReturn(new Result([]));
        $this->hydrator->method('hydrateAll')->willReturn([$profile]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $entity = new RelationTestUserWithProfile();
        $entity->id = 1;
        $entity->name = 'Alice';

        // Load profile with a nested plan (profile has no sub-relations so this is a no-op)
        $fetchPlan = FetchPlan::withNested(['profile' => FetchPlan::with(['avatar'])]);
        $loader->loadRelations([$entity], $fetchPlan);

        self::assertSame($profile, $entity->profile);
    }

    #[Test]
    public function loadHasManyWithNestedFetchPlanTriggersNestedLoading(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: RelationTestPost::class,
            foreignKey: 'user_id',
            localKey: 'id',
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

        $userMetadata = new EntityMetadata(
            entityClass: RelationTestUserWithPosts::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
            relations: ['posts' => $relation],
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

        $postPk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );
        $postUserIdCol = new ColumnMetadata(
            propertyName: 'userId',
            columnName: 'user_id',
            type: ColumnType::Integer,
        );
        $titleCol = new ColumnMetadata(
            propertyName: 'title',
            columnName: 'title',
            type: ColumnType::String,
        );

        $postMetadata = new EntityMetadata(
            entityClass: RelationTestPost::class,
            tableName: 'posts',
            schema: null,
            primaryKey: $postPk,
            columns: ['id' => $postPk, 'userId' => $postUserIdCol, 'title' => $titleCol],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithPosts::class => $userMetadata,
                RelationTestPost::class => $postMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        $post = new RelationTestPost();
        $post->id = 100;
        $post->userId = 1;
        $post->title = 'First Post';

        $this->connection->method('query')->willReturn(new Result([]));
        $this->hydrator->method('hydrateAll')->willReturn([$post]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $user = new RelationTestUserWithPosts();
        $user->id = 1;
        $user->name = 'Alice';

        $fetchPlan = FetchPlan::withNested(['posts' => FetchPlan::with(['comments'])]);
        $loader->loadRelations([$user], $fetchPlan);

        self::assertCount(1, $user->posts);
        self::assertSame($post, $user->posts[0]);
    }

    #[Test]
    public function loadBelongsToManyWithMultipleParentsDistributesCorrectly(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'tags',
            type: RelationType::BelongsToMany,
            targetEntity: RelationTestTag::class,
            foreignKey: 'user_id',
            localKey: 'id',
            pivotTable: 'user_tags',
            pivotForeignKey: 'user_id',
            pivotRelatedKey: 'tag_id',
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

        $userMetadata = new EntityMetadata(
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

        $tagPk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );
        $tagLabelCol = new ColumnMetadata(
            propertyName: 'label',
            columnName: 'label',
            type: ColumnType::String,
        );

        $tagMetadata = new EntityMetadata(
            entityClass: RelationTestTag::class,
            tableName: 'tags',
            schema: null,
            primaryKey: $tagPk,
            columns: ['id' => $tagPk, 'label' => $tagLabelCol],
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')
            ->willReturnCallback(fn(string $class) => match ($class) {
                RelationTestUserWithTags::class => $userMetadata,
                RelationTestTag::class => $tagMetadata,
                default => throw new RuntimeException('Unexpected class: ' . $class),
            });

        // Pivot: user 1 has tag 10+20, user 2 has tag 20
        $pivotRows = new Result([
            new Row(['user_id' => 1, 'tag_id' => 10]),
            new Row(['user_id' => 1, 'tag_id' => 20]),
            new Row(['user_id' => 2, 'tag_id' => 20]),
        ]);

        $tag1 = new RelationTestTag();
        $tag1->id = 10;
        $tag1->label = 'PHP';

        $tag2 = new RelationTestTag();
        $tag2->id = 20;
        $tag2->label = 'ORM';

        $callCount = 0;
        $this->connection->method('query')
            ->willReturnCallback(function () use (&$callCount, $pivotRows): Result {
                $callCount++;
                if ($callCount === 1) {
                    return $pivotRows;
                }

                return new Result([]);
            });

        $this->hydrator->method('hydrateAll')->willReturn([$tag1, $tag2]);

        $loader = new RelationLoader(
            $this->connection,
            $metadataRegistry,
            $this->hydrator,
        );

        $user1 = new RelationTestUserWithTags();
        $user1->id = 1;
        $user1->name = 'Alice';

        $user2 = new RelationTestUserWithTags();
        $user2->id = 2;
        $user2->name = 'Bob';

        $fetchPlan = FetchPlan::with(['tags']);
        $loader->loadRelations([$user1, $user2], $fetchPlan);

        self::assertCount(2, $user1->tags);
        self::assertSame($tag1, $user1->tags[0]);
        self::assertSame($tag2, $user1->tags[1]);

        self::assertCount(1, $user2->tags);
        self::assertSame($tag2, $user2->tags[0]);
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

class RelationTestProfile
{
    public int $id;
    public int $userId;
    public string $bio = '';
}

class RelationTestUserWithProfile
{
    public int $id;
    public string $name = '';
    public ?object $profile = null;
}

class RelationTestPost
{
    public int $id;
    public int $userId;
    public string $title = '';
}

class RelationTestUserWithPosts
{
    public int $id;
    public string $name = '';
    /** @var list<object> */
    public array $posts = [];
}
