<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\CommentEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;

final class WhereHasTest extends TestCase
{
    private function makeConnection(): ConnectionInterface
    {
        $stub = $this->createStub(ConnectionInterface::class);
        $stub->method('driver')->willReturn(Driver::SQLite);

        return $stub;
    }

    private function makeRegistry(EntityMetadata ...$metadataList): MetadataRegistryInterface
    {
        $map = [];
        foreach ($metadataList as $meta) {
            $map[$meta->entityClass] = $meta;
        }

        $stub = $this->createStub(MetadataRegistryInterface::class);
        $stub->method('get')->willReturnCallback(
            static fn(string $class): EntityMetadata => $map[$class],
        );

        return $stub;
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

    #[Test]
    public function whereHasGeneratesExistsSubqueryForHasMany(): void
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
        $registry = $this->makeRegistry($userMeta, $postMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));
        $builder->whereHas('posts', $registry);

        $compiled = $builder->toSql();

        self::assertStringContainsString('EXISTS', $compiled['sql']);
        self::assertStringContainsString('"posts"', $compiled['sql']);
    }

    #[Test]
    public function whereDoesntHaveGeneratesNotExistsSubquery(): void
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
        $registry = $this->makeRegistry($userMeta, $postMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));
        $builder->whereDoesntHave('posts', $registry);

        $compiled = $builder->toSql();

        self::assertStringContainsString('NOT EXISTS', $compiled['sql']);
    }

    #[Test]
    public function whereHasWithCallbackAddsConstraints(): void
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
        $registry = $this->makeRegistry($userMeta, $postMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));
        $builder->whereHas('posts', $registry, static function (SelectBuilder $sub): void {
            $sub->where('id', 42);
        });

        $compiled = $builder->toSql();

        self::assertStringContainsString('EXISTS', $compiled['sql']);
    }

    #[Test]
    public function whereHasThrowsWithoutEntityConfiguration(): void
    {
        $builder = new SelectBuilder($this->makeConnection());
        $builder->from('users');

        $registry = $this->createStub(MetadataRegistryInterface::class);

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('whereHas requires entity-aware query');

        $builder->whereHas('posts', $registry);
    }

    #[Test]
    public function whereHasThrowsForUnknownRelation(): void
    {
        $userMeta = $this->userMetadata();
        $registry = $this->makeRegistry($userMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('Unknown relation "nonexistent"');

        $builder->whereHas('nonexistent', $registry);
    }

    #[Test]
    public function whereHasForBelongsToRelation(): void
    {
        $belongsToRelation = new RelationMetadata(
            propertyName: 'author',
            type: RelationType::BelongsTo,
            targetEntity: UserEntity::class,
            foreignKey: 'author_id',
            localKey: 'id',
        );

        $postMeta = $this->postMetadata(['author' => $belongsToRelation]);
        $userMeta = $this->userMetadata();
        $registry = $this->makeRegistry($postMeta, $userMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(PostEntity::class, $postMeta, $this->createStub(EntityHydratorInterface::class));
        $builder->whereHas('author', $registry);

        $compiled = $builder->toSql();

        self::assertStringContainsString('EXISTS', $compiled['sql']);
        self::assertStringContainsString('"users"', $compiled['sql']);
    }

    #[Test]
    public function whereHasForMorphManyRelation(): void
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
        $registry = $this->makeRegistry($postMeta, $commentMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(PostEntity::class, $postMeta, $this->createStub(EntityHydratorInterface::class));
        $builder->whereHas('comments', $registry);

        $compiled = $builder->toSql();

        self::assertStringContainsString('EXISTS', $compiled['sql']);
        self::assertStringContainsString('"comments"', $compiled['sql']);
        self::assertStringContainsString('"commentable_type"', $compiled['sql']);
    }

    #[Test]
    public function whereHasForHasOneRelation(): void
    {
        $hasOneRelation = new RelationMetadata(
            propertyName: 'profile',
            type: RelationType::HasOne,
            targetEntity: PostEntity::class,
            foreignKey: 'author_id',
            localKey: 'id',
        );

        $userMeta = $this->userMetadata(['profile' => $hasOneRelation]);
        $postMeta = $this->postMetadata();
        $registry = $this->makeRegistry($userMeta, $postMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));
        $builder->whereHas('profile', $registry);

        $compiled = $builder->toSql();

        self::assertStringContainsString('EXISTS', $compiled['sql']);
    }

    #[Test]
    public function whereHasRejectsMorphToRelation(): void
    {
        $morphToRelation = new RelationMetadata(
            propertyName: 'commentable',
            type: RelationType::MorphTo,
            targetEntity: PostEntity::class,
            foreignKey: 'commentable_id',
            localKey: 'id',
            morphTypeColumn: 'commentable_type',
            morphIdColumn: 'commentable_id',
        );

        $commentMeta = $this->commentMetadata();
        $commentMetaWithRelation = new EntityMetadata(
            entityClass: CommentEntity::class,
            tableName: 'comments',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: $commentMeta->columns,
            relations: ['commentable' => $morphToRelation],
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

        $postMeta = $this->postMetadata();
        $registry = $this->makeRegistry($commentMetaWithRelation, $postMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(CommentEntity::class, $commentMetaWithRelation, $this->createStub(EntityHydratorInterface::class));

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('whereHas does not support relation type "morph_to"');

        $builder->whereHas('commentable', $registry);
    }
}
