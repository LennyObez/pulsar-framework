<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity;

use function count;
use function preg_match;
use function preg_split;
use function str_contains;
use function trim;

/**
 * The aggregate statement filters the same rows the SELECT filters.
 *
 * SelectBuilder::aggregate() hands AggregateBuilder a hand-assembled statement
 * instead of reusing toSql(), so the two only agree while every row-selecting
 * predicate is reproduced on both sides. The soft-delete filter was not: it is
 * added at compile time by compileSoftDeleteFilters() rather than stored in
 * $this->wheres, so paginate() counted trashed rows the page then excluded —
 * "127 results" over 119 reachable ones — and GenericRepository::exists()
 * answered true for an entity find() reports as gone.
 */
final class AggregateFilterParityTest extends TestCase
{
    /** @var list<string> */
    private array $executed = [];

    /** @var list<array<string, mixed>> */
    private array $executedBindings = [];

    private function makeConnection(int $aggregateValue = 42): ConnectionInterface
    {
        $stub = $this->createStub(ConnectionInterface::class);
        $stub->method('driver')->willReturn(Driver::SQLite);
        $stub->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []) use ($aggregateValue): Result {
                $this->executed[] = $sql;
                $this->executedBindings[] = $bindings;

                if (str_contains($sql, 'AS aggregate')) {
                    return Result::fromArrays([['aggregate' => $aggregateValue]]);
                }

                return Result::fromArrays([['id' => 1, 'user_id' => 7, 'deleted_at' => null]]);
            },
        );

        return $stub;
    }

    private function softDeleteMetadata(): EntityMetadata
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true);

        return new EntityMetadata(
            entityClass: PostEntity::class,
            tableName: 'posts',
            schema: null,
            primaryKey: $pk,
            columns: [
                'id' => $pk,
                'user_id' => new ColumnMetadata('userId', 'user_id', ColumnType::Integer),
                'status' => new ColumnMetadata('status', 'status', ColumnType::String),
                'deleted_at' => new ColumnMetadata('deletedAt', 'deleted_at', ColumnType::DateTime, nullable: true),
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

    private function builderOn(ConnectionInterface $connection): SelectBuilder
    {
        $builder = new SelectBuilder($connection);
        $builder->forEntity(
            PostEntity::class,
            $this->softDeleteMetadata(),
            $this->createStub(EntityHydratorInterface::class),
        );

        return $builder;
    }

    /**
     * The WHERE clause of a compiled statement, or '' when it has none.
     */
    private function whereClauseOf(string $sql): string
    {
        if (preg_match('/ WHERE (.*)$/', $sql, $matches) !== 1) {
            return '';
        }

        /** @var list<string> $tail */
        $tail = preg_split('/ (?:GROUP BY|ORDER BY|LIMIT|OFFSET|FOR) /', $matches[1], 2) ?: [''];

        return trim($tail[0]);
    }

    #[Test]
    public function aggregateAppliesTheSoftDeleteFilterThatSelectApplies(): void
    {
        $builder = $this->builderOn($this->makeConnection());

        self::assertSame(42, $builder->aggregate()->count());

        self::assertCount(1, $this->executed);
        self::assertStringContainsString('"t0"."deleted_at" IS NULL', $this->executed[0]);
    }

    #[Test]
    public function paginateCountsExactlyTheRowsItsPageCanReach(): void
    {
        $builder = $this->builderOn($this->makeConnection(119));
        $builder->where('status', 'published');

        $result = $builder->paginate(1, 15);

        self::assertSame(119, $result->total);
        self::assertCount(2, $this->executed);

        [$countSql, $pageSql] = [$this->executed[0], $this->executed[1]];

        self::assertStringContainsString('AS aggregate', $countSql);
        self::assertSame(
            $this->whereClauseOf($pageSql),
            $this->whereClauseOf($countSql),
            'The total and the page must be computed over the same rows.',
        );
        self::assertStringContainsString('"t0"."deleted_at" IS NULL', $countSql);
        self::assertSame($this->executedBindings[0], $this->executedBindings[1]);
    }

    #[Test]
    public function withTrashedLiftsTheFilterFromTheAggregateToo(): void
    {
        $builder = $this->builderOn($this->makeConnection());
        $builder->withTrashed();

        self::assertSame(42, $builder->aggregate()->count());

        self::assertCount(1, $this->executed);
        self::assertStringNotContainsString('deleted_at', $this->executed[0]);
    }

    #[Test]
    public function onlyTrashedInvertsTheFilterInTheAggregateToo(): void
    {
        $builder = $this->builderOn($this->makeConnection());
        $builder->onlyTrashed();

        self::assertSame(42, $builder->aggregate()->count());

        self::assertCount(1, $this->executed);
        self::assertStringContainsString('"t0"."deleted_at" IS NOT NULL', $this->executed[0]);
    }

    #[Test]
    public function softDeleteFilterPrecedesTheCallersOwnPredicates(): void
    {
        $builder = $this->builderOn($this->makeConnection());
        $builder->where('status', 'published');

        self::assertSame(42, $builder->aggregate()->count());

        // Same order as toSql(): the scope filter first, then the caller's.
        self::assertMatchesRegularExpression(
            '/WHERE "t0"\."deleted_at" IS NULL AND "t0"\."status" = :/',
            $this->executed[0],
        );
    }

    #[Test]
    public function aggregateRefusesAGroupedQueryInsteadOfCountingItFlat(): void
    {
        $builder = $this->builderOn($this->makeConnection());
        $builder->groupBy('status');

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessageIsOrContains('GROUP BY or HAVING');

        $builder->aggregate();
    }

    #[Test]
    public function aggregateRefusesAHavingClauseWhoseParametersItCannotBind(): void
    {
        $builder = $this->builderOn($this->makeConnection());
        $builder->having(RawExpression::of('COUNT(*) > :threshold', ['threshold' => 5]));

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessageIsOrContains('GROUP BY or HAVING');

        $builder->aggregate();
    }

    #[Test]
    public function anEntityWithoutSoftDeletesIsUnaffected(): void
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true);
        $metadata = new EntityMetadata(
            entityClass: PostEntity::class,
            tableName: 'posts',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk],
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

        $builder = new SelectBuilder($this->makeConnection());
        $builder->forEntity(PostEntity::class, $metadata, $this->createStub(EntityHydratorInterface::class));

        self::assertSame(42, $builder->aggregate()->count());

        self::assertSame(1, count($this->executed));
        self::assertStringNotContainsString('WHERE', $this->executed[0]);
    }
}
