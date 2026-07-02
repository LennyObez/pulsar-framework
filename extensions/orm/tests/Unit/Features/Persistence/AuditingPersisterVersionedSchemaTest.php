<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;

final class AuditingPersisterVersionedSchemaTest extends TestCase
{
    #[Test]
    public function versionedUpdateTargetsSchemaQualifiedTable(): void
    {
        // FR-29: the optimistic-locking UPDATE was built with the bare table
        // name while every other write used the schema-qualified name. On a
        // schema-scoped connection the bare name resolves via search_path to the
        // wrong table, the UPDATE affects 0 rows, and the change is misreported
        // as a stale-entity conflict. The emitted SQL must carry the schema.
        $metadata = $this->versionedMetadata();

        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturn($metadata);

        $captured = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturnCallback(
            function (string $sql) use (&$captured): int {
                $captured[] = $sql;

                return 1;
            },
        );

        $persister = new AuditingPersister($connection, $registry, new EntityDehydrator($registry));

        $persister->update(new VersionedSchemaEntity(), MutationContext::system('test'));

        self::assertCount(1, $captured);
        self::assertStringContainsString('app_schema', $captured[0], 'versioned UPDATE must target the schema-qualified table');
    }

    private function versionedMetadata(): EntityMetadata
    {
        $idCol = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            nullable: false,
            isPrimaryKey: true,
            autoIncrement: true,
            isVersion: false,
            encrypted: false,
            blindIndexColumn: null,
            blindIndexHashLength: null,
            insertable: true,
            updatable: false,
            casterClass: null,
        );

        $versionCol = new ColumnMetadata(
            propertyName: 'version',
            columnName: 'version',
            type: ColumnType::Integer,
            nullable: false,
            isPrimaryKey: false,
            autoIncrement: false,
            isVersion: true,
            encrypted: false,
            blindIndexColumn: null,
            blindIndexHashLength: null,
            insertable: true,
            updatable: false,
            casterClass: null,
        );

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
            nullable: false,
            isPrimaryKey: false,
            autoIncrement: false,
            isVersion: false,
            encrypted: false,
            blindIndexColumn: null,
            blindIndexHashLength: null,
            insertable: true,
            updatable: true,
            casterClass: null,
        );

        return new EntityMetadata(
            entityClass: VersionedSchemaEntity::class,
            tableName: 'items',
            schema: 'app_schema',
            primaryKey: $idCol,
            columns: ['id' => $idCol, 'version' => $versionCol, 'name' => $nameCol],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: 'version',
            encryptedColumns: [],
        );
    }
}

/**
 * Plain entity whose properties the dehydrator reads by reflection.
 *
 * @internal
 */
final class VersionedSchemaEntity
{
    public int $id = 7;

    public int $version = 3;

    public string $name = 'Widget';
}
