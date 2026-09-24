<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Relation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
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

/**
 * `withCount()` counts the same related rows `with()` would hand back.
 *
 * RelationLoader configures its query with forEntity(), so the target entity's
 * soft-delete scope applies and a trashed child never appears in a loaded
 * relation. WithCountLoader used only from(), leaving the builder with no
 * entity metadata and therefore no soft-delete filter — so the badge said
 * "12 comments" next to a list that showed 9.
 */
final class WithCountSoftDeleteTest extends TestCase
{
    /** @var list<string> */
    private array $executed = [];

    private function makeConnection(): ConnectionInterface
    {
        $stub = $this->createStub(ConnectionInterface::class);
        $stub->method('driver')->willReturn(Driver::SQLite);
        $stub->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []): Result {
                $this->executed[] = $sql;

                return Result::fromArrays([]);
            },
        );

        return $stub;
    }

    /** @param array<string, RelationMetadata> $relations */
    private function userMetadata(array $relations = []): EntityMetadata
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true);

        return new EntityMetadata(
            entityClass: UserEntity::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk],
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
    private function postMetadata(array $relations = [], bool $softDelete = true): EntityMetadata
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true);

        return new EntityMetadata(
            entityClass: PostEntity::class,
            tableName: 'posts',
            schema: null,
            primaryKey: $pk,
            columns: [
                'id' => $pk,
                'author_id' => new ColumnMetadata('author_id', 'author_id', ColumnType::Integer),
            ],
            relations: $relations,
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: $softDelete,
            softDeleteColumn: $softDelete ? 'deleted_at' : null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    private function commentMetadata(): EntityMetadata
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true);

        return new EntityMetadata(
            entityClass: CommentEntity::class,
            tableName: 'comments',
            schema: null,
            primaryKey: $pk,
            columns: [
                'id' => $pk,
                'commentable_type' => new ColumnMetadata('commentable_type', 'commentable_type', ColumnType::String),
                'commentable_id' => new ColumnMetadata('commentable_id', 'commentable_id', ColumnType::Integer),
            ],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: true,
            softDeleteColumn: 'deleted_at',
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    private function registry(EntityMetadata ...$metadataList): MetadataRegistryInterface
    {
        $map = [];
        foreach ($metadataList as $meta) {
            $map[$meta->entityClass] = $meta;
        }

        $stub = $this->createStub(MetadataRegistryInterface::class);
        $stub->method('get')->willReturnCallback(
            static fn(string $class): EntityMetadata => $map[$class]
                ?? throw new RuntimeException("Unexpected: {$class}"),
        );

        return $stub;
    }

    #[Test]
    public function hasManyCountExcludesTrashedChildren(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: PostEntity::class,
            foreignKey: 'author_id',
            localKey: 'id',
        );

        $loader = new WithCountLoader(
            $this->makeConnection(),
            $this->registry($this->userMetadata(['posts' => $relation]), $this->postMetadata()),
        );

        $loader->loadCounts([new UserEntity(id: 1)], ['posts']);

        self::assertCount(1, $this->executed);
        self::assertStringContainsString('"t0"."deleted_at" IS NULL', $this->executed[0]);
    }

    #[Test]
    public function morphManyCountExcludesTrashedChildren(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'comments',
            type: RelationType::MorphMany,
            targetEntity: CommentEntity::class,
            foreignKey: 'commentable_id',
            localKey: 'id',
            morphTypeColumn: 'commentable_type',
            morphIdColumn: 'commentable_id',
        );

        $loader = new WithCountLoader(
            $this->makeConnection(),
            $this->registry($this->postMetadata(['comments' => $relation]), $this->commentMetadata()),
        );

        $loader->loadCounts([new PostEntity(id: 1)], ['comments']);

        self::assertCount(1, $this->executed);
        self::assertStringContainsString('"t0"."deleted_at" IS NULL', $this->executed[0]);
    }

    #[Test]
    public function aTargetWithoutSoftDeletesGainsNoFilter(): void
    {
        $relation = new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: PostEntity::class,
            foreignKey: 'author_id',
            localKey: 'id',
        );

        $loader = new WithCountLoader(
            $this->makeConnection(),
            $this->registry(
                $this->userMetadata(['posts' => $relation]),
                $this->postMetadata(softDelete: false),
            ),
        );

        $loader->loadCounts([new UserEntity(id: 1)], ['posts']);

        self::assertCount(1, $this->executed);
        self::assertStringNotContainsString('deleted_at', $this->executed[0]);
    }
}
