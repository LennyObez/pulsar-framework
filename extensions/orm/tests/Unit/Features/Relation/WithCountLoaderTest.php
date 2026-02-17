<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Relation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Features\Relation\WithCountLoader;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\CommentEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;
use RuntimeException;

final class WithCountLoaderTest extends TestCase
{
    #[Test]
    public function loadCountsReturnsEmptyForEmptyEntities(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $registry = $this->createStub(MetadataRegistryInterface::class);

        $loader = new WithCountLoader($connection, $registry);
        $result = $loader->loadCounts([], ['posts']);

        self::assertSame([], $result);
    }

    #[Test]
    public function loadCountsSkipsUnknownRelation(): void
    {
        $userMeta = $this->userMetadata();
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturn($userMeta);

        $loader = new WithCountLoader($connection, $registry);

        $user = new UserEntity(id: 1, name: 'Alice', email: 'alice@test.com');
        $result = $loader->loadCounts([$user], ['nonexistent_relation']);

        self::assertSame([], $result);
    }

    #[Test]
    public function loadCountsForHasManyRelation(): void
    {
        $hasManyRelation = new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: PostEntity::class,
            foreignKey: 'author_id',
            localKey: 'id',
        );

        $userMeta = $this->userMetadata(['posts' => $hasManyRelation]);
        $postMeta = $this->postMetadata();

        $countResult = new Result([
            new Row(['author_id' => 1, 'cnt' => 3]),
            new Row(['author_id' => 2, 'cnt' => 1]),
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn($countResult);

        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturnCallback(
            static fn(string $class): EntityMetadata => match ($class) {
                UserEntity::class => $userMeta,
                PostEntity::class => $postMeta,
                default => throw new RuntimeException("Unexpected: $class"),
            },
        );

        $loader = new WithCountLoader($connection, $registry);

        $user1 = new UserEntity(id: 1, name: 'Alice', email: 'alice@test.com');
        $user2 = new UserEntity(id: 2, name: 'Bob', email: 'bob@test.com');

        $counts = $loader->loadCounts([$user1, $user2], ['posts']);

        self::assertArrayHasKey('posts', $counts);
        $postCounts = $counts['posts'];
        self::assertSame(3, $postCounts['1'] ?? null);
        self::assertSame(1, $postCounts['2'] ?? null);
    }

    #[Test]
    public function loadCountsForMorphManyRelation(): void
    {
        $morphManyRelation = new RelationMetadata(
            propertyName: 'comments',
            type: RelationType::MorphMany,
            targetEntity: CommentEntity::class,
            foreignKey: 'commentable_id',
            localKey: 'id',
            morphTypeColumn: 'commentable_type',
            morphIdColumn: 'commentable_id',
        );

        $postMeta = $this->postMetadata(['comments' => $morphManyRelation]);
        $commentMeta = $this->commentMetadata();

        $countResult = new Result([
            new Row(['commentable_id' => 1, 'cnt' => 5]),
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn($countResult);

        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturnCallback(
            static fn(string $class): EntityMetadata => match ($class) {
                PostEntity::class => $postMeta,
                CommentEntity::class => $commentMeta,
                default => throw new RuntimeException("Unexpected: $class"),
            },
        );

        $loader = new WithCountLoader($connection, $registry);

        $post = new PostEntity(id: 1);
        $counts = $loader->loadCounts([$post], ['comments']);

        self::assertArrayHasKey('comments', $counts);
        $commentCounts = $counts['comments'];
        self::assertSame(5, $commentCounts['1'] ?? null);
    }

    #[Test]
    public function morphManyCountSkipsWithoutMorphColumns(): void
    {
        $brokenRelation = new RelationMetadata(
            propertyName: 'comments',
            type: RelationType::MorphMany,
            targetEntity: CommentEntity::class,
            foreignKey: 'commentable_id',
            localKey: 'id',
            morphTypeColumn: null,
            morphIdColumn: null,
        );

        $postMeta = $this->postMetadata(['comments' => $brokenRelation]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturn($postMeta);

        $loader = new WithCountLoader($connection, $registry);

        $post = new PostEntity(id: 1);
        $counts = $loader->loadCounts([$post], ['comments']);

        self::assertArrayHasKey('comments', $counts);
        self::assertSame([], $counts['comments']);
    }

    /** @param array<string, RelationMetadata> $relations */
    private function userMetadata(array $relations = []): EntityMetadata
    {
        return new EntityMetadata(
            entityClass: UserEntity::class,
            tableName: 'users',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: [
                'id' => new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
                'name' => new ColumnMetadata('name', 'name', ColumnType::String),
                'email' => new ColumnMetadata('email', 'email', ColumnType::String),
            ],
            relations: $relations,
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

    /** @param array<string, RelationMetadata> $relations */
    private function postMetadata(array $relations = []): EntityMetadata
    {
        return new EntityMetadata(
            entityClass: PostEntity::class,
            tableName: 'posts',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: [
                'id' => new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
                'author_id' => new ColumnMetadata('author_id', 'author_id', ColumnType::Integer),
            ],
            relations: $relations,
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

    private function commentMetadata(): EntityMetadata
    {
        return new EntityMetadata(
            entityClass: CommentEntity::class,
            tableName: 'comments',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: [
                'id' => new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
                'body' => new ColumnMetadata('body', 'body', ColumnType::String),
                'commentable_type' => new ColumnMetadata('commentable_type', 'commentable_type', ColumnType::String),
                'commentable_id' => new ColumnMetadata('commentable_id', 'commentable_id', ColumnType::Integer),
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
}
