<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
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
}
