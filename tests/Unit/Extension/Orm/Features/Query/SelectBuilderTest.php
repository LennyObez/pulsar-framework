<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Domain\LikePattern;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Domain\SortDirection;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;
use Pulsar\Extension\Orm\Features\Query\JoinOnBuilder;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use stdClass;

#[CoversClass(SelectBuilder::class)]
final class SelectBuilderTest extends TestCase
{
    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    private function builder(): SelectBuilder
    {
        return new SelectBuilder($this->connection)->from('users');
    }

    #[Test]
    public function basicSelectAllFromTable(): void
    {
        $compiled = $this->builder()->toSql();

        self::assertStringContainsString('SELECT', $compiled['sql']);
        self::assertStringContainsString('`users`', $compiled['sql']);
        self::assertSame([], $compiled['bindings']);
    }

    #[Test]
    public function selectSpecificColumns(): void
    {
        $compiled = $this->builder()
            ->select(['name', 'email'])
            ->toSql();

        self::assertStringContainsString('`t0`.`name`', $compiled['sql']);
        self::assertStringContainsString('`t0`.`email`', $compiled['sql']);
    }

    #[Test]
    public function whereEquality(): void
    {
        $compiled = $this->builder()
            ->where('id', 42)
            ->toSql();

        self::assertStringContainsString('WHERE', $compiled['sql']);
        self::assertStringContainsString('`t0`.`id`', $compiled['sql']);
        self::assertCount(1, $compiled['bindings']);
        self::assertContains(42, $compiled['bindings']);
    }

    #[Test]
    public function whereWithOperator(): void
    {
        $compiled = $this->builder()
            ->whereOp('age', '>=', 18)
            ->toSql();

        self::assertStringContainsString('>=', $compiled['sql']);
        self::assertContains(18, $compiled['bindings']);
    }

    #[Test]
    public function whereNull(): void
    {
        $compiled = $this->builder()
            ->whereNull('deleted_at')
            ->toSql();

        self::assertStringContainsString('`t0`.`deleted_at` IS NULL', $compiled['sql']);
        self::assertSame([], $compiled['bindings']);
    }

    #[Test]
    public function whereNotNull(): void
    {
        $compiled = $this->builder()
            ->whereNotNull('email')
            ->toSql();

        self::assertStringContainsString('`t0`.`email` IS NOT NULL', $compiled['sql']);
    }

    #[Test]
    public function whereIn(): void
    {
        $compiled = $this->builder()
            ->whereIn('status', ['active', 'pending'])
            ->toSql();

        self::assertStringContainsString('IN', $compiled['sql']);
        self::assertCount(2, $compiled['bindings']);
    }

    #[Test]
    public function whereNotIn(): void
    {
        $compiled = $this->builder()
            ->whereNotIn('role', ['banned'])
            ->toSql();

        self::assertStringContainsString('NOT IN', $compiled['sql']);
    }

    #[Test]
    public function whereBetween(): void
    {
        $compiled = $this->builder()
            ->whereBetween('age', 18, 65)
            ->toSql();

        self::assertStringContainsString('BETWEEN', $compiled['sql']);
        self::assertCount(2, $compiled['bindings']);
    }

    #[Test]
    public function whereLike(): void
    {
        $compiled = $this->builder()
            ->whereLike('name', LikePattern::contains('john'))
            ->toSql();

        self::assertStringContainsString('LIKE', $compiled['sql']);
        self::assertContains('%john%', $compiled['bindings']);
    }

    #[Test]
    public function whereRaw(): void
    {
        $compiled = $this->builder()
            ->whereRaw(RawExpression::of('score > :min', ['min' => 100]))
            ->toSql();

        self::assertStringContainsString('score > :min', $compiled['sql']);
        self::assertSame(100, $compiled['bindings']['min']);
    }

    #[Test]
    public function orderByAscending(): void
    {
        $compiled = $this->builder()
            ->orderBy('name')
            ->toSql();

        self::assertStringContainsString('ORDER BY', $compiled['sql']);
        self::assertStringContainsString('ASC', $compiled['sql']);
    }

    #[Test]
    public function orderByDescending(): void
    {
        $compiled = $this->builder()
            ->orderBy('created_at', SortDirection::Desc)
            ->toSql();

        self::assertStringContainsString('DESC', $compiled['sql']);
    }

    #[Test]
    public function limitAndOffset(): void
    {
        $compiled = $this->builder()
            ->limit(10)
            ->offset(20)
            ->toSql();

        self::assertStringContainsString('LIMIT 10', $compiled['sql']);
        self::assertStringContainsString('OFFSET 20', $compiled['sql']);
    }

    #[Test]
    public function groupByAndHaving(): void
    {
        $compiled = $this->builder()
            ->groupBy('status')
            ->having(RawExpression::of('COUNT(*) > :cnt', ['cnt' => 5]))
            ->toSql();

        self::assertStringContainsString('GROUP BY', $compiled['sql']);
        self::assertStringContainsString('HAVING', $compiled['sql']);
        self::assertSame(5, $compiled['bindings']['cnt']);
    }

    #[Test]
    public function lockForUpdate(): void
    {
        $compiled = $this->builder()
            ->lock(LockMode::ForUpdate)
            ->toSql();

        self::assertStringContainsString('FOR UPDATE', $compiled['sql']);
    }

    #[Test]
    public function innerJoinCompilesCorrectly(): void
    {
        $compiled = $this->builder()
            ->innerJoin('orders', 'o', static function (JoinOnBuilder $on): void {
                $on->on('t0.id', '=', 'o.user_id');
            })
            ->toSql();

        self::assertStringContainsString('INNER JOIN', $compiled['sql']);
        self::assertStringContainsString('`orders`', $compiled['sql']);
    }

    #[Test]
    public function leftJoinCompilesCorrectly(): void
    {
        $compiled = $this->builder()
            ->leftJoin('profiles', 'p', static function (JoinOnBuilder $on): void {
                $on->on('t0.id', '=', 'p.user_id');
            })
            ->toSql();

        self::assertStringContainsString('LEFT JOIN', $compiled['sql']);
    }

    #[Test]
    public function duplicateAliasThrows(): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->builder()
            ->innerJoin('orders', 'o', static function (JoinOnBuilder $on): void {
                $on->on('t0.id', '=', 'o.user_id');
            })
            ->innerJoin('other', 'o', static function (JoinOnBuilder $on): void {
                $on->on('t0.id', '=', 'o.other_id');
            });
    }

    #[Test]
    public function fromWithCustomAlias(): void
    {
        $builder = new SelectBuilder($this->connection)->from('users', 'u');
        $compiled = $builder->toSql();

        self::assertStringContainsString('`users` AS `u`', $compiled['sql']);
    }

    #[Test]
    public function getExecutesQueryOnConnection(): void
    {
        $expectedResult = new Result([new Row(['id' => 1])]);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('query')
            ->willReturn($expectedResult);

        $result = new SelectBuilder($connection)
            ->from('users')
            ->get();

        self::assertSame($expectedResult, $result);
    }

    #[Test]
    public function firstSetsLimitToOne(): void
    {
        $row = new Row(['id' => 1, 'name' => 'Alice']);
        $result = new Result([$row]);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('query')
            ->with(self::stringContains('LIMIT 1'), self::anything())
            ->willReturn($result);

        $first = new SelectBuilder($connection)
            ->from('users')
            ->first();

        self::assertSame($row, $first);
    }

    #[Test]
    public function firstReturnsNullForEmptyResult(): void
    {
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('query')->willReturn($result);

        $first = new SelectBuilder($connection)
            ->from('users')
            ->first();

        self::assertNull($first);
    }

    #[Test]
    public function getEntitiesThrowsWithoutHydrator(): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->builder()->getEntities();
    }

    #[Test]
    public function firstEntityThrowsWithoutHydrator(): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->builder()->firstEntity();
    }

    #[Test]
    public function withFetchPlanSetsPlan(): void
    {
        $plan = FetchPlan::with(['orders']);
        $builder = $this->builder()->withFetchPlan($plan);

        self::assertSame($plan, $builder->getFetchPlan());
    }

    #[Test]
    public function multipleWhereConditionsCompileWithAnd(): void
    {
        $compiled = $this->builder()
            ->where('name', 'Alice')
            ->where('age', 30)
            ->toSql();

        self::assertStringContainsString('AND', $compiled['sql']);
        self::assertCount(2, $compiled['bindings']);
    }

    #[Test]
    public function selectWithRawExpression(): void
    {
        $compiled = $this->builder()
            ->select([RawExpression::of('COUNT(*) AS total')])
            ->toSql();

        self::assertStringContainsString('COUNT(*) AS total', $compiled['sql']);
    }

    #[Test]
    public function qualifiedColumnPassesThroughUnchanged(): void
    {
        $compiled = $this->builder()
            ->where('t0.id', 1)
            ->toSql();

        self::assertStringContainsString('`t0`.`id`', $compiled['sql']);
    }

    #[Test]
    public function forEntityConfiguresBuilder(): void
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);
        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'users',
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

        $hydrator = $this->createStub(EntityHydratorInterface::class);
        $hydrator->method('hydrateAll')->willReturn([new stdClass()]);

        $result = new Result([new Row(['id' => 1])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('query')->willReturn($result);

        $builder = new SelectBuilder($connection)
            ->forEntity(stdClass::class, $metadata, $hydrator);

        $entities = $builder->getEntities();
        self::assertCount(1, $entities);
    }

    #[Test]
    public function softDeleteFilterAppliedWhenMetadataHasSoftDelete(): void
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);
        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk],
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

        $hydrator = $this->createStub(EntityHydratorInterface::class);

        $builder = new SelectBuilder($this->connection)
            ->forEntity(stdClass::class, $metadata, $hydrator);

        $compiled = $builder->toSql();

        self::assertStringContainsString('IS NULL', $compiled['sql']);
        self::assertStringContainsString('deleted_at', $compiled['sql']);
    }

    #[Test]
    public function withTrashedSkipsSoftDeleteFilter(): void
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);
        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk],
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

        $hydrator = $this->createStub(EntityHydratorInterface::class);

        $builder = new SelectBuilder($this->connection)
            ->forEntity(stdClass::class, $metadata, $hydrator)
            ->withTrashed();

        $compiled = $builder->toSql();

        self::assertStringNotContainsString('IS NULL', $compiled['sql']);
    }

    #[Test]
    public function onlyTrashedFiltersForDeletedRecords(): void
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);
        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk],
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

        $hydrator = $this->createStub(EntityHydratorInterface::class);

        $builder = new SelectBuilder($this->connection)
            ->forEntity(stdClass::class, $metadata, $hydrator)
            ->onlyTrashed();

        $compiled = $builder->toSql();

        self::assertStringContainsString('IS NOT NULL', $compiled['sql']);
        self::assertStringContainsString('deleted_at', $compiled['sql']);
    }
}
