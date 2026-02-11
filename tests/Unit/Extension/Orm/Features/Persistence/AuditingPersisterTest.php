<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(AuditingPersister::class)]
final class AuditingPersisterTest extends TestCase
{
    private MetadataRegistryInterface&Stub $metadataRegistry;

    protected function setUp(): void
    {
        $this->metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
    }

    #[Test]
    public function insertExecutesInsertAndLogsAudit(): void
    {
        $metadata = $this->buildSimpleMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())->method('execute');
        $connection->method('lastInsertId')->willReturn('1');

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                'admin',
                'orm.insert',
                self::stringContains('PersisterTestEntity'),
                self::isArray(),
            );

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
            $auditLogger,
        );

        $entity = new PersisterTestEntity();
        $entity->id = 0;
        $entity->name = 'Alice';

        $context = new MutationContext(actor: 'admin', reason: 'test insert');
        $persister->insert($entity, $context);

        // Auto-increment should set ID back on entity
        self::assertSame(1, $entity->id);
    }

    #[Test]
    public function insertSetsTimestampsWhenConfigured(): void
    {
        $metadata = $this->buildTimestampedMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $executedSql = '';
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql = $sql;

                return 1;
            });
        $connection->method('lastInsertId')->willReturn('1');

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
        );

        $entity = new PersisterTimestampedEntity();
        $entity->id = 0;
        $entity->name = 'test';

        $context = MutationContext::system('test');
        $persister->insert($entity, $context);

        // The INSERT SQL should contain the timestamp columns
        self::assertStringContainsString('created_at', $executedSql);
        self::assertStringContainsString('updated_at', $executedSql);
    }

    #[Test]
    public function updateExecutesUpdateAndLogsAudit(): void
    {
        $metadata = $this->buildSimpleMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->willReturn(1);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                'admin',
                'orm.update',
                self::anything(),
                self::isArray(),
            );

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
            $auditLogger,
        );

        $entity = new PersisterTestEntity();
        $entity->id = 42;
        $entity->name = 'Updated';

        $context = new MutationContext(actor: 'admin', reason: 'test update');
        $persister->update($entity, $context);
    }

    #[Test]
    public function deleteExecutesDeleteAndLogsAudit(): void
    {
        $metadata = $this->buildSimpleMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->willReturn(1);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                'admin',
                'orm.delete',
                self::anything(),
                self::isArray(),
            );

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
            $auditLogger,
        );

        $entity = new PersisterTestEntity();
        $entity->id = 42;
        $entity->name = 'ToDelete';

        $context = new MutationContext(actor: 'admin', reason: 'test delete');
        $persister->delete($entity, $context);
    }

    #[Test]
    public function deletePerformsSoftDeleteWhenConfigured(): void
    {
        $metadata = $this->buildSoftDeleteMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $executedSql = '';
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql = $sql;

                return 1;
            });

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
        );

        $entity = new PersisterTestEntity();
        $entity->id = 10;
        $entity->name = 'SoftDelete';

        $context = MutationContext::system('soft delete test');
        $persister->delete($entity, $context);

        // Soft delete does UPDATE, not DELETE
        self::assertStringContainsString('UPDATE', $executedSql);
        self::assertStringContainsString('deleted_at', $executedSql);
    }

    #[Test]
    public function insertWithoutAuditLoggerDoesNotFail(): void
    {
        $metadata = $this->buildSimpleMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('execute')->willReturn(1);
        $connection->method('lastInsertId')->willReturn('1');

        $dehydrator = new EntityDehydrator($this->metadataRegistry);
        $persister = new AuditingPersister(
            $connection,
            $this->metadataRegistry,
            $dehydrator,
        );

        $entity = new PersisterTestEntity();
        $entity->id = 0;
        $entity->name = 'test';

        $context = MutationContext::system('test');

        // Should not throw -- audit logger is optional
        $persister->insert($entity, $context);

        self::assertSame(1, $entity->id);
    }

    private function buildSimpleMetadata(): EntityMetadata
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
            entityClass: PersisterTestEntity::class,
            tableName: 'entities',
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

    private function buildTimestampedMetadata(): EntityMetadata
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
            entityClass: PersisterTimestampedEntity::class,
            tableName: 'timestamped',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
            relations: [],
            hasTimestamps: true,
            createdAtColumn: 'created_at',
            updatedAtColumn: 'updated_at',
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    private function buildSoftDeleteMetadata(): EntityMetadata
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
            entityClass: PersisterTestEntity::class,
            tableName: 'entities',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol],
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
}

/**
 * Stub entity for persister tests.
 */
class PersisterTestEntity
{
    public int $id;
    public string $name = '';
}

/**
 * Stub entity for timestamped persister tests.
 */
class PersisterTimestampedEntity
{
    public int $id;
    public string $name = '';
}
