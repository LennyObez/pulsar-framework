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
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\CommentEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;

use function array_count_values;
use function array_keys;
use function array_unique;
use function array_values;
use function preg_match_all;
use function sort;

/**
 * A whereHas()/whereDoesntHave() subquery draws its placeholder names from the
 * same sequence as the query that hosts it.
 *
 * Before that was true, the subquery started its own count at zero, so an outer
 * `:p0` and a subquery `:p0` named the same parameter. Merging the two binding
 * maps then let one value overwrite the other — no error, no warning, just a
 * WHERE clause filtering on a value nobody asked for. These tests hold the
 * sequence shared: every placeholder emitted anywhere in the statement is bound
 * exactly once, to the value its own clause supplied.
 */
final class SubqueryBindingIsolationTest extends TestCase
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
        $pk = new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true);

        return new EntityMetadata(
            entityClass: UserEntity::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: [
                'id' => $pk,
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

    private function postMetadata(): EntityMetadata
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

    private function postsRelation(): RelationMetadata
    {
        return new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: PostEntity::class,
            foreignKey: 'author_id',
            localKey: 'id',
        );
    }

    private function commentsRelation(): RelationMetadata
    {
        return new RelationMetadata(
            propertyName: 'comments',
            type: RelationType::MorphMany,
            targetEntity: CommentEntity::class,
            foreignKey: 'commentable_id',
            localKey: 'id',
            morphTypeColumn: 'commentable_type',
            morphIdColumn: 'commentable_id',
        );
    }

    /**
     * Every `:name` the SQL mentions, in source order, duplicates included.
     *
     * @return list<string>
     */
    private function placeholdersIn(string $sql): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);

        /** @var list<string> $names */
        $names = $matches[1];

        return $names;
    }

    /**
     * The statement is self-consistent: no placeholder is emitted by two
     * clauses, and the binding map answers each one exactly once.
     *
     * @param array<string, mixed> $bindings
     */
    private function assertBindingsAreSound(string $sql, array $bindings): void
    {
        $names = $this->placeholdersIn($sql);

        foreach (array_count_values($names) as $name => $occurrences) {
            self::assertSame(
                1,
                $occurrences,
                "Placeholder :{$name} is emitted {$occurrences} times — two clauses are sharing one name.",
            );
        }

        $distinct = array_values(array_unique($names));
        sort($distinct);

        $bound = array_keys($bindings);
        sort($bound);

        self::assertSame(
            $distinct,
            $bound,
            'Every placeholder in the SQL must have exactly one binding, and vice versa.',
        );
    }

    #[Test]
    public function outerBindingSurvivesAWhereHasThatUsesTheSamePlaceholderShape(): void
    {
        $userMeta = $this->userMetadata(['posts' => $this->postsRelation()]);
        $registry = $this->makeRegistry($userMeta, $this->postMetadata());

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        // The outer predicate claims the first name in the sequence.
        $builder->where('name', 'alice');

        // The subquery's own predicate is what used to claim it a second time.
        $builder->whereHas('posts', $registry, static function (SelectBuilder $sub): void {
            $sub->where('title', 'HIJACKED');
        });

        $compiled = $builder->toSql();

        $this->assertBindingsAreSound($compiled['sql'], $compiled['bindings']);

        self::assertContains(
            'alice',
            $compiled['bindings'],
            'The outer WHERE value was overwritten by the subquery.',
        );
        self::assertContains('HIJACKED', $compiled['bindings']);
        self::assertCount(2, $compiled['bindings']);
    }

    #[Test]
    public function twoSubqueriesOnOneQueryDoNotOverwriteEachOther(): void
    {
        $userMeta = $this->userMetadata([
            'posts' => $this->postsRelation(),
            'comments' => $this->commentsRelation(),
        ]);
        $registry = $this->makeRegistry($userMeta, $this->postMetadata(), $this->commentMetadata());

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        $builder->whereHas('posts', $registry, static function (SelectBuilder $sub): void {
            $sub->where('title', 'first-subquery');
        });
        $builder->whereDoesntHave('comments', $registry, static function (SelectBuilder $sub): void {
            $sub->where('body', 'second-subquery');
        });

        $compiled = $builder->toSql();

        $this->assertBindingsAreSound($compiled['sql'], $compiled['bindings']);

        self::assertContains('first-subquery', $compiled['bindings']);
        self::assertContains('second-subquery', $compiled['bindings']);
    }

    #[Test]
    public function aSubqueryCannotClaimAPlaceholderTheOuterQueryWillLaterUse(): void
    {
        $userMeta = $this->userMetadata(['posts' => $this->postsRelation()]);
        $registry = $this->makeRegistry($userMeta, $this->postMetadata());

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        // Subquery first: the outer predicates that follow must not be able to
        // land on a name the subquery has already spent.
        $builder->whereHas('posts', $registry, static function (SelectBuilder $sub): void {
            $sub->where('title', 'subquery-value');
        });
        $builder->where('name', 'outer-name');
        $builder->whereIn('email', ['a@example.test', 'b@example.test']);

        $compiled = $builder->toSql();

        $this->assertBindingsAreSound($compiled['sql'], $compiled['bindings']);

        foreach (['subquery-value', 'outer-name', 'a@example.test', 'b@example.test'] as $expected) {
            self::assertContains($expected, $compiled['bindings']);
        }
        self::assertCount(4, $compiled['bindings']);
    }

    #[Test]
    public function morphDiscriminatorAndSubqueryPredicateKeepSeparateBindings(): void
    {
        $userMeta = $this->userMetadata(['comments' => $this->commentsRelation()]);
        $registry = $this->makeRegistry($userMeta, $this->commentMetadata());

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        $builder->where('name', 'alice');
        $builder->whereHas('comments', $registry, static function (SelectBuilder $sub): void {
            $sub->where('body', 'needle');
        });

        $compiled = $builder->toSql();

        $this->assertBindingsAreSound($compiled['sql'], $compiled['bindings']);

        self::assertContains(UserEntity::class, $compiled['bindings']);
        self::assertContains('alice', $compiled['bindings']);
        self::assertContains('needle', $compiled['bindings']);
        self::assertCount(3, $compiled['bindings']);
    }

    #[Test]
    public function theSubqueryReadsTheTargetsSchemaQualifiedTable(): void
    {
        $userMeta = $this->userMetadata(['posts' => $this->postsRelation()]);

        $postPk = new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true);
        $postMeta = new EntityMetadata(
            entityClass: PostEntity::class,
            tableName: 'posts',
            schema: 'publishing',
            primaryKey: $postPk,
            columns: ['id' => $postPk],
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

        $registry = $this->makeRegistry($userMeta, $postMeta);

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));
        $builder->whereHas('posts', $registry);

        $compiled = $builder->toSql();

        // An unqualified "posts" resolves against the connection's search path,
        // which is a different table from the one the relation points at.
        self::assertStringContainsString('"publishing"."posts" AS "sub0"', $compiled['sql']);
    }

    #[Test]
    public function aRawExpressionCannotQuietlyRebindAGeneratedPlaceholder(): void
    {
        $userMeta = $this->userMetadata();

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        // Claims :p0 through the counter.
        $builder->where('name', 'alice');

        // A raw expression picks its own names, so it is the one path left by
        // which two clauses can claim one parameter. It has to be refused.
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessageIsOrContains('":p0" is already bound');

        $builder->whereRaw(RawExpression::of('"t0"."id" > :p0', ['p0' => 999]));
    }

    #[Test]
    public function aRawExpressionCannotRebindASubqueryPlaceholderEither(): void
    {
        $userMeta = $this->userMetadata(['posts' => $this->postsRelation()]);
        $registry = $this->makeRegistry($userMeta, $this->postMetadata());

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        $builder->whereHas('posts', $registry, static function (SelectBuilder $sub): void {
            $sub->where('title', 'subquery-value');
        });

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessageIsOrContains('is already bound');

        $builder->whereRaw(RawExpression::of('"t0"."id" > :p0', ['p0' => 999]));
    }

    #[Test]
    public function aRawExpressionWithANameOfItsOwnIsAccepted(): void
    {
        $userMeta = $this->userMetadata();

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        $builder->where('name', 'alice');
        $builder->whereRaw(RawExpression::of('"t0"."id" > :floor', ['floor' => 999]));

        $compiled = $builder->toSql();

        $this->assertBindingsAreSound($compiled['sql'], $compiled['bindings']);
        self::assertContains('alice', $compiled['bindings']);
        self::assertContains(999, $compiled['bindings']);
    }

    #[Test]
    public function subqueryConstraintsOnJoinsAlsoStayOutOfTheOuterNamespace(): void
    {
        $userMeta = $this->userMetadata(['posts' => $this->postsRelation()]);
        $registry = $this->makeRegistry($userMeta, $this->postMetadata());

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(UserEntity::class, $userMeta, $this->createStub(EntityHydratorInterface::class));

        $builder->whereBetween('id', 10, 20);
        $builder->whereHas('posts', $registry, static function (SelectBuilder $sub): void {
            $sub->whereBetween('id', 100, 200);
        });

        $compiled = $builder->toSql();

        $this->assertBindingsAreSound($compiled['sql'], $compiled['bindings']);

        foreach ([10, 20, 100, 200] as $expected) {
            self::assertContains($expected, $compiled['bindings']);
        }
        self::assertCount(4, $compiled['bindings']);
    }
}
