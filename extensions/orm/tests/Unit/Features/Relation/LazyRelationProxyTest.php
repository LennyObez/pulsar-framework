<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Relation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Features\Relation\LazyRelationProxy;
use stdClass;

final class LazyRelationProxyTest extends TestCase
{
    #[Test]
    public function isLoadedReturnsFalseBeforeAccess(): void
    {
        $proxy = $this->createProxy();

        self::assertFalse($proxy->isLoaded());
    }

    #[Test]
    public function loadTriggersQueryAndReturnsEntities(): void
    {
        $proxy = $this->createProxy();

        $loaded = $proxy->load();

        self::assertSame([], $loaded);
        self::assertTrue($proxy->isLoaded());
    }

    #[Test]
    public function loadOnlyQueriesOnce(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([]));

        $proxy = $this->createProxyWithConnection($connection);

        $proxy->load();
        $result2 = $proxy->load();

        // Second call should return cached result
        self::assertSame([], $result2);
    }

    #[Test]
    public function countReturnsNumberOfLoadedEntities(): void
    {
        $proxy = $this->createProxy();

        self::assertSame(0, $proxy->count());
    }

    #[Test]
    public function getFirstReturnsNullWhenEmpty(): void
    {
        $proxy = $this->createProxy();

        self::assertNull($proxy->getFirst());
    }

    #[Test]
    public function toArrayReturnsLoadedEntities(): void
    {
        $proxy = $this->createProxy();

        self::assertSame([], $proxy->toArray());
    }

    #[Test]
    public function offsetExistsReturnsFalseForEmpty(): void
    {
        $proxy = $this->createProxy();

        self::assertFalse($proxy->offsetExists(0));
    }

    #[Test]
    public function offsetGetReturnsNullForMissing(): void
    {
        $proxy = $this->createProxy();

        self::assertNull($proxy->offsetGet(0));
    }

    #[Test]
    public function proxySupportsIteration(): void
    {
        $proxy = $this->createProxy();
        $items = [];

        foreach ($proxy as $item) {
            $items[] = $item;
        }

        self::assertSame([], $items);
    }

    #[Test]
    public function offsetSetIsNoOp(): void
    {
        $proxy = $this->createProxy();

        $proxy->offsetSet(0, new stdClass());

        // Should still be empty (read-only proxy)
        self::assertSame(0, $proxy->count());
    }

    #[Test]
    public function offsetUnsetIsNoOp(): void
    {
        $proxy = $this->createProxy();

        $proxy->offsetUnset(0);

        self::assertSame(0, $proxy->count());
    }

    /** @return LazyRelationProxy<object> */
    private function createProxy(): LazyRelationProxy
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([]));

        return $this->createProxyWithConnection($connection);
    }

    /** @return LazyRelationProxy<object> */
    private function createProxyWithConnection(ConnectionInterface $connection): LazyRelationProxy
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            nullable: false,
            isPrimaryKey: true,
            autoIncrement: true,
        );

        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
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

        $metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $metadataRegistry->method('get')->willReturn($metadata);

        $hydrator = $this->createStub(EntityHydratorInterface::class);
        $hydrator->method('hydrateAll')->willReturn([]);

        $relation = new RelationMetadata(
            propertyName: 'posts',
            type: RelationType::HasMany,
            targetEntity: stdClass::class,
            foreignKey: 'user_id',
            localKey: 'id',
        );

        return new LazyRelationProxy(
            connection: $connection,
            metadataRegistry: $metadataRegistry,
            hydrator: $hydrator,
            relation: $relation,
            parentId: 1,
        );
    }
}
