<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\OptimisticLockException;
use Pulsar\Extension\Orm\Exception\TenantIsolationException;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Tenancy\TenantColumnResolver;
use Pulsar\Extension\Orm\Features\Tenancy\TenantInsertEnricher;
use stdClass;

final class AuditingPersisterTenantTest extends TestCase
{
    #[Test]
    public function insertEnrichesTenantColumn(): void
    {
        $tenantScope = $this->createStub(TenantScopeInterface::class);
        $tenantScope->method('isActive')->willReturn(true);
        $tenantScope->method('currentTenantId')->willReturn('tenant_42');

        $config = OrmConfig::fromArray(['tenant_column' => 'tenant_id']);
        $columnResolver = new TenantColumnResolver($config);
        $enricher = new TenantInsertEnricher($tenantScope, $columnResolver);

        $pkCol = new ColumnMetadata(
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

        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'orders',
            schema: null,
            primaryKey: $pkCol,
            columns: ['id' => $pkCol],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: true,
            tenantColumn: 'tenant_id',
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );

        $values = ['name' => 'Test Order'];
        $enrichedValues = $enricher->enrich($values, $metadata);

        self::assertSame('tenant_42', $enrichedValues['tenant_id']);
        self::assertSame('Test Order', $enrichedValues['name']);
    }

    #[Test]
    public function insertDoesNotOverrideExistingTenantColumn(): void
    {
        $tenantScope = $this->createStub(TenantScopeInterface::class);
        $tenantScope->method('isActive')->willReturn(true);
        $tenantScope->method('currentTenantId')->willReturn('tenant_99');

        $config = OrmConfig::fromArray(['tenant_column' => 'tenant_id']);
        $columnResolver = new TenantColumnResolver($config);
        $enricher = new TenantInsertEnricher($tenantScope, $columnResolver);

        $pkCol = new ColumnMetadata(
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

        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'orders',
            schema: null,
            primaryKey: $pkCol,
            columns: ['id' => $pkCol],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: true,
            tenantColumn: 'tenant_id',
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );

        $values = ['name' => 'X', 'tenant_id' => 'explicit_tenant'];
        $enriched = $enricher->enrich($values, $metadata);

        self::assertSame('explicit_tenant', $enriched['tenant_id']);
    }

    #[Test]
    public function insertSkipsEnrichmentForNonTenantEntity(): void
    {
        $tenantScope = $this->createStub(TenantScopeInterface::class);
        $tenantScope->method('isActive')->willReturn(true);
        $tenantScope->method('currentTenantId')->willReturn('tenant_1');

        $config = OrmConfig::fromArray(['tenant_column' => 'tenant_id']);
        $columnResolver = new TenantColumnResolver($config);
        $enricher = new TenantInsertEnricher($tenantScope, $columnResolver);

        $pkCol = new ColumnMetadata(
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

        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'settings',
            schema: null,
            primaryKey: $pkCol,
            columns: ['id' => $pkCol],
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

        $values = ['key' => 'color', 'value' => 'blue'];
        $result = $enricher->enrich($values, $metadata);

        self::assertArrayNotHasKey('tenant_id', $result);
    }

    #[Test]
    public function insertSkipsEnrichmentForSharedEntity(): void
    {
        $tenantScope = $this->createStub(TenantScopeInterface::class);
        $tenantScope->method('isActive')->willReturn(true);
        $tenantScope->method('currentTenantId')->willReturn('tenant_1');

        $config = OrmConfig::fromArray(['tenant_column' => 'tenant_id']);
        $columnResolver = new TenantColumnResolver($config);
        $enricher = new TenantInsertEnricher($tenantScope, $columnResolver);

        $pkCol = new ColumnMetadata(
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

        $metadata = new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'lookups',
            schema: null,
            primaryKey: $pkCol,
            columns: ['id' => $pkCol],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: true,
            tenantColumn: 'tenant_id',
            isTenantShared: true,
            versionProperty: null,
            encryptedColumns: [],
        );

        $values = ['name' => 'Shared item'];
        $result = $enricher->enrich($values, $metadata);

        self::assertArrayNotHasKey('tenant_id', $result);
    }

    // --- Write-side tenant isolation -----------------------------------------
    //
    // A cross-tenant UPDATE/DELETE must never silently succeed. Every tenant-
    // scoped write path (versioned UPDATE, plain UPDATE, soft-delete UPDATE,
    // hard DELETE) applies a `WHERE tenant_column = :active_tenant` predicate;
    // when that predicate matches no row the write is rejected — surfaced as a
    // TenantIsolationException (or, on the versioned path, an
    // OptimisticLockException) rather than a no-op the caller mistakes for
    // success.

    #[Test]
    public function tenantScopedUpdateConstrainsTheWriteToTheActiveTenant(): void
    {
        $sql = '';
        $bindings = [];
        $connection = $this->connectionReturning(1, $sql, $bindings);

        $persister = $this->persisterForTenant(
            $connection,
            $this->tenantMetadata(TenantWriteEntity::class),
            'tenant_a',
        );

        $persister->update(new TenantWriteEntity(), MutationContext::system('test'));

        self::assertStringContainsString('tenant_id', $sql, 'the UPDATE must carry the tenant predicate');
        self::assertContains('tenant_a', $bindings, 'the predicate must bind the active tenant');
    }

    #[Test]
    public function crossTenantUpdateMatchingNoRowRaisesIsolationException(): void
    {
        $sql = '';
        $bindings = [];
        $connection = $this->connectionReturning(0, $sql, $bindings);

        $persister = $this->persisterForTenant(
            $connection,
            $this->tenantMetadata(TenantWriteEntity::class),
            'tenant_a',
        );

        $this->expectException(TenantIsolationException::class);

        $persister->update(new TenantWriteEntity(), MutationContext::system('test'));
    }

    #[Test]
    public function crossTenantHardDeleteMatchingNoRowRaisesIsolationException(): void
    {
        $sql = '';
        $bindings = [];
        $connection = $this->connectionReturning(0, $sql, $bindings);

        $persister = $this->persisterForTenant(
            $connection,
            $this->tenantMetadata(TenantWriteEntity::class),
            'tenant_a',
        );

        $this->expectException(TenantIsolationException::class);

        $persister->delete(new TenantWriteEntity(), MutationContext::system('test'));
    }

    #[Test]
    public function crossTenantSoftDeleteMatchingNoRowRaisesIsolationException(): void
    {
        $sql = '';
        $bindings = [];
        $connection = $this->connectionReturning(0, $sql, $bindings);

        $persister = $this->persisterForTenant(
            $connection,
            $this->tenantMetadata(TenantWriteEntity::class, softDelete: true),
            'tenant_a',
        );

        $this->expectException(TenantIsolationException::class);

        $persister->delete(new TenantWriteEntity(), MutationContext::system('test'));
    }

    #[Test]
    public function singleTenantWriteDoesNotRaiseIsolationExceptionOnZeroAffected(): void
    {
        // No tenant scope bound (single-tenant app): a zero-affected UPDATE is an
        // ordinary no-op and must NOT be reinterpreted as an isolation failure,
        // and no tenant predicate is emitted.
        $sql = '';
        $bindings = [];
        $connection = $this->connectionReturning(0, $sql, $bindings);

        $persister = $this->persisterForTenant(
            $connection,
            $this->tenantMetadata(TenantWriteEntity::class),
            null,
        );

        $persister->update(new TenantWriteEntity(), MutationContext::system('test'));

        self::assertStringNotContainsString('tenant_id', $sql, 'no tenant predicate without a tenant scope');
        self::assertNotContains('tenant_a', $bindings);
    }

    #[Test]
    public function versionedTenantScopedUpdateConstrainsTheWriteToTheActiveTenant(): void
    {
        $sql = '';
        $bindings = [];
        $connection = $this->connectionReturning(1, $sql, $bindings);

        $persister = $this->persisterForTenant(
            $connection,
            $this->tenantMetadata(TenantVersionedWriteEntity::class, versionProperty: 'version'),
            'tenant_a',
        );

        $persister->update(new TenantVersionedWriteEntity(), MutationContext::system('test'));

        self::assertStringContainsString('tenant_id', $sql, 'the versioned UPDATE must carry the tenant predicate');
        self::assertContains('tenant_a', $bindings, 'the predicate must bind the active tenant');
    }

    #[Test]
    public function crossTenantVersionedUpdateIsBlockedAsOptimisticLock(): void
    {
        // On the versioned path a cross-tenant UPDATE matches no row and is
        // rejected as a stale-entity conflict. The write is blocked either way —
        // what matters for isolation is that it never silently succeeds.
        $sql = '';
        $bindings = [];
        $connection = $this->connectionReturning(0, $sql, $bindings);

        $persister = $this->persisterForTenant(
            $connection,
            $this->tenantMetadata(TenantVersionedWriteEntity::class, versionProperty: 'version'),
            'tenant_a',
        );

        $this->expectException(OptimisticLockException::class);

        $persister->update(new TenantVersionedWriteEntity(), MutationContext::system('test'));
    }

    /**
     * Build an AuditingPersister whose writes run against a recording stub
     * connection. When $tenantId is non-null the persister is wired with a real
     * TenantInsertEnricher whose scope is active for that tenant; when null the
     * persister is single-tenant (no enricher).
     *
     * @param class-string $entityClass unused directly, but the metadata's
     *                                   entityClass must match the entity passed
     *                                   to update()/delete()
     */
    private function persisterForTenant(
        ConnectionInterface $connection,
        EntityMetadata $metadata,
        ?string $tenantId,
    ): AuditingPersister {
        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturn($metadata);

        $enricher = null;
        if ($tenantId !== null) {
            $tenantScope = $this->createStub(TenantScopeInterface::class);
            $tenantScope->method('isActive')->willReturn(true);
            $tenantScope->method('currentTenantId')->willReturn($tenantId);

            $columnResolver = new TenantColumnResolver(OrmConfig::fromArray(['tenant_column' => 'tenant_id']));
            $enricher = new TenantInsertEnricher($tenantScope, $columnResolver);
        }

        return new AuditingPersister($connection, $registry, new EntityDehydrator($registry), null, $enricher);
    }

    /**
     * A stub connection that records the last executed SQL and bindings (by
     * reference) and reports $affected rows for every write.
     *
     * @param array<string, mixed> $capturedBindings
     */
    private function connectionReturning(int $affected, string &$capturedSql, array &$capturedBindings): ConnectionInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturnCallback(
            function (string $sql, array $bindings) use (&$capturedSql, &$capturedBindings, $affected): int {
                $capturedSql = $sql;
                $capturedBindings = $bindings;

                return $affected;
            },
        );

        return $connection;
    }

    /**
     * Tenant-scoped metadata for the write-side fixtures. Column set is minimal:
     * an auto-increment primary key, an updatable `name`, and (optionally) a
     * version column. The tenant column is carried as `tenantColumn`, exactly as
     * a compiled #[TenantScoped] entity would express it.
     *
     * @param class-string $entityClass
     */
    private function tenantMetadata(
        string $entityClass,
        bool $softDelete = false,
        ?string $versionProperty = null,
    ): EntityMetadata {
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

        $columns = ['id' => $idCol, 'name' => $nameCol];

        if ($versionProperty !== null) {
            $columns[$versionProperty] = new ColumnMetadata(
                propertyName: $versionProperty,
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
        }

        return new EntityMetadata(
            entityClass: $entityClass,
            tableName: 'orders',
            schema: null,
            primaryKey: $idCol,
            columns: $columns,
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: $softDelete,
            softDeleteColumn: $softDelete ? 'deleted_at' : null,
            isTenantScoped: true,
            tenantColumn: 'tenant_id',
            isTenantShared: false,
            versionProperty: $versionProperty,
            encryptedColumns: [],
        );
    }
}

/**
 * Plain tenant-scoped entity whose properties the dehydrator reads by reflection.
 *
 * @internal
 */
final class TenantWriteEntity
{
    public int $id = 5;

    public string $name = 'Row';
}

/**
 * Versioned tenant-scoped entity for the optimistic-locking write path.
 *
 * @internal
 */
final class TenantVersionedWriteEntity
{
    public int $id = 5;

    public string $name = 'Row';

    public int $version = 2;
}
