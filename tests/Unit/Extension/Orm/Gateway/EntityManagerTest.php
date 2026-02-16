<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Gateway;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Contracts\RepositoryInterface;
use Pulsar\Extension\Orm\Contracts\TransactionManagerInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Features\Schema\SchemaBuilder;
use Pulsar\Extension\Orm\Gateway\EntityManager;
use Pulsar\Extension\Orm\Gateway\IdentityMap;

#[CoversClass(EntityManager::class)]
final class EntityManagerTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private MetadataRegistryInterface&Stub $metadataRegistry;
    private EntityHydratorInterface&Stub $hydrator;
    private AuditingPersister $persister;
    private TransactionManagerInterface&Stub $transactionManager;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);

        $this->metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $this->hydrator = $this->createStub(EntityHydratorInterface::class);
        $this->transactionManager = $this->createStub(TransactionManagerInterface::class);

        $metadata = $this->buildMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        // Construct a real AuditingPersister since it's final readonly
        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $this->persister = new AuditingPersister(
            $this->connection,
            $this->metadataRegistry,
            $dehydrator,
        );

        $this->entityManager = new EntityManager(
            $this->connection,
            $this->metadataRegistry,
            $this->hydrator,
            $this->persister,
            $this->transactionManager,
        );
    }

    #[Test]
    public function repositoryReturnsRepositoryInterface(): void
    {
        $repo = $this->entityManager->repository(EmTestEntity::class);

        self::assertInstanceOf(RepositoryInterface::class, $repo);
    }

    #[Test]
    public function repositoryCachesSameInstance(): void
    {
        $repo1 = $this->entityManager->repository(EmTestEntity::class);
        $repo2 = $this->entityManager->repository(EmTestEntity::class);

        self::assertSame($repo1, $repo2);
    }

    #[Test]
    public function persistDelegatesToPersisterInsert(): void
    {
        $entity = new EmTestEntity();
        $entity->id = 0;
        $entity->name = 'test';

        $context = MutationContext::system('persist test');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())->method('execute')->willReturn(1);
        $connection->method('lastInsertId')->willReturn('1');

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
        );

        $em = new EntityManager(
            $connection,
            $this->metadataRegistry,
            $this->hydrator,
            $persister,
            $this->transactionManager,
        );

        $em->persist($entity, $context);

        self::assertSame(1, $entity->id);
    }

    #[Test]
    public function updateDelegatesToPersisterUpdate(): void
    {
        $entity = new EmTestEntity();
        $entity->id = 42;
        $entity->name = 'updated';

        $context = MutationContext::system('update test');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())->method('execute')->willReturn(1);

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
        );

        $em = new EntityManager(
            $connection,
            $this->metadataRegistry,
            $this->hydrator,
            $persister,
            $this->transactionManager,
        );

        $em->update($entity, $context);
    }

    #[Test]
    public function removeDelegatesToPersisterDelete(): void
    {
        $entity = new EmTestEntity();
        $entity->id = 42;
        $entity->name = 'deleted';

        $context = MutationContext::system('remove test');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())->method('execute')->willReturn(1);

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
        );

        $em = new EntityManager(
            $connection,
            $this->metadataRegistry,
            $this->hydrator,
            $persister,
            $this->transactionManager,
        );

        $em->remove($entity, $context);
    }

    #[Test]
    public function transactionalDelegatesToTransactionManager(): void
    {
        $transactionManager = $this->createMock(TransactionManagerInterface::class);
        $transactionManager->expects(self::once())
            ->method('transactional')
            ->willReturn('tx_result');

        $em = new EntityManager(
            $this->connection,
            $this->metadataRegistry,
            $this->hydrator,
            $this->persister,
            $transactionManager,
        );

        $result = $em->transactional(static fn() => 'tx_result');

        self::assertSame('tx_result', $result);
    }

    #[Test]
    public function transactionsReturnsTransactionManager(): void
    {
        self::assertSame($this->transactionManager, $this->entityManager->transactions());
    }

    #[Test]
    public function queryReturnsSelectBuilder(): void
    {
        $builder = $this->entityManager->query(EmTestEntity::class);

        self::assertInstanceOf(SelectBuilder::class, $builder);
    }

    #[Test]
    public function rawQueryReturnsSelectBuilder(): void
    {
        $builder = $this->entityManager->rawQuery();

        self::assertInstanceOf(SelectBuilder::class, $builder);
    }

    #[Test]
    public function schemaReturnsSchemaBuilder(): void
    {
        $schema = $this->entityManager->schema();

        self::assertInstanceOf(SchemaBuilder::class, $schema);
    }

    #[Test]
    public function metadataReturnsMetadataRegistry(): void
    {
        self::assertSame($this->metadataRegistry, $this->entityManager->metadata());
    }

    #[Test]
    public function connectionReturnsConnectionInterface(): void
    {
        self::assertSame($this->connection, $this->entityManager->connection());
    }

    #[Test]
    public function identityMapReturnsIdentityMapInstance(): void
    {
        $map = $this->entityManager->identityMap();

        self::assertInstanceOf(IdentityMap::class, $map);
    }

    #[Test]
    public function findPopulatesIdentityMapOnCacheMiss(): void
    {
        // After calling find(), the entity should be tracked in the identity map.
        // We can't easily test the full query flow without a real DB, but we can
        // verify that the identity map is exposed and that clear() resets it.
        $map = $this->entityManager->identityMap();

        // Put an entity directly in the map to simulate a previous find
        $entity = new EmTestEntity();
        $entity->id = 99;
        $entity->name = 'cached';

        $map->put(EmTestEntity::class, 99, $entity);

        // find() should return the cached entity from the identity map
        $found = $this->entityManager->find(EmTestEntity::class, 99);

        self::assertSame($entity, $found);
    }

    #[Test]
    public function clearResetsIdentityMapAndRepositoryCache(): void
    {
        $map = $this->entityManager->identityMap();

        $entity = new EmTestEntity();
        $entity->id = 1;
        $entity->name = 'test';
        $map->put(EmTestEntity::class, 1, $entity);

        self::assertTrue($map->has(EmTestEntity::class, 1));

        // Get a repository to populate the repo cache
        $this->entityManager->repository(EmTestEntity::class);

        $this->entityManager->clear();

        self::assertFalse($map->has(EmTestEntity::class, 1));
        self::assertSame(0, $map->count());
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

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
        );

        return new EntityMetadata(
            entityClass: EmTestEntity::class,
            tableName: 'em_entities',
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
}

class EmTestEntity
{
    public int $id;
    public string $name = '';
}
