<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\EntityNotFoundException;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Persistence\GenericRepository;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;

#[CoversClass(GenericRepository::class)]
final class GenericRepositoryTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private MetadataRegistryInterface&Stub $metadataRegistry;
    private EntityHydratorInterface&Stub $hydrator;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);

        $this->metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
        $this->hydrator = $this->createStub(EntityHydratorInterface::class);

        $metadata = $this->buildMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);
    }

    #[Test]
    public function insertDelegatesToPersister(): void
    {
        $entity = new RepositoryTestEntity();
        $entity->id = 0;
        $entity->name = 'test';

        $context = MutationContext::system('test');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())->method('execute')->willReturn(1);
        $connection->method('lastInsertId')->willReturn('1');

        $repo = $this->createRepository($connection);
        $repo->insert($entity, $context);

        // Auto-increment should set ID back on entity
        self::assertSame(1, $entity->id);
    }

    #[Test]
    public function updateDelegatesToPersister(): void
    {
        $entity = new RepositoryTestEntity();
        $entity->id = 42;
        $entity->name = 'updated';

        $context = MutationContext::system('test');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())->method('execute')->willReturn(1);

        $repo = $this->createRepository($connection);
        $repo->update($entity, $context);
    }

    #[Test]
    public function deleteDelegatesToPersister(): void
    {
        $entity = new RepositoryTestEntity();
        $entity->id = 42;
        $entity->name = 'deleted';

        $context = MutationContext::system('test');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())->method('execute')->willReturn(1);

        $repo = $this->createRepository($connection);
        $repo->delete($entity, $context);
    }

    #[Test]
    public function queryReturnsSelectBuilder(): void
    {
        $repo = $this->createRepository();
        $builder = $repo->query();

        self::assertInstanceOf(SelectBuilder::class, $builder);
    }

    #[Test]
    public function findOrFailThrowsWhenNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $emptyResult = new Result([]);
        $this->connection->method('query')->willReturn($emptyResult);

        $repo = $this->createRepository();
        $repo->findOrFail(999);
    }

    #[Test]
    public function findReturnsNullWhenNotFound(): void
    {
        $emptyResult = new Result([]);
        $this->connection->method('query')->willReturn($emptyResult);

        $repo = $this->createRepository();
        $found = $repo->find(999);

        self::assertNull($found);
    }

    /** @return GenericRepository<RepositoryTestEntity> */
    private function createRepository(?ConnectionInterface $connection = null): GenericRepository
    {
        $conn = $connection ?? $this->connection;
        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $conn,
            $this->metadataRegistry,
            $dehydrator,
        );

        return new GenericRepository(
            $conn,
            $this->metadataRegistry,
            $this->hydrator,
            $persister,
            RepositoryTestEntity::class,
        );
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
            entityClass: RepositoryTestEntity::class,
            tableName: 'repo_entities',
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

/**
 * Stub entity for repository tests.
 */
class RepositoryTestEntity
{
    public int $id;
    public string $name = '';
}
