<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Config\MetadataCacheConfig;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Features\Tenancy\TenantColumnResolver;
use Pulsar\Extension\Orm\Features\Tenancy\TenantScopeApplier;
use stdClass;

#[CoversClass(TenantScopeApplier::class)]
#[CoversClass(TenantColumnResolver::class)]
final class TenantScopeApplierTest extends TestCase
{
    private TenantScopeInterface&Stub $tenantScope;
    private TenantScopeApplier $applier;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->tenantScope = $this->createStub(TenantScopeInterface::class);
        $config = new OrmConfig(
            connection: 'default',
            metadataCache: new MetadataCacheConfig('array', ''),
            encryption: new EncryptionConfig(false, 5, 'orm__enc', 'orm__bidx'),
            tenantColumn: 'tenant_id',
            softDeleteColumn: 'deleted_at',
        );
        $resolver = new TenantColumnResolver($config);
        $this->applier = new TenantScopeApplier($this->tenantScope, $resolver);

        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    private function buildMetadata(
        bool $isTenantScoped,
        ?string $tenantColumn = null,
        bool $isTenantShared = false,
    ): EntityMetadata {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);

        return new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'entities',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: $isTenantScoped,
            tenantColumn: $tenantColumn,
            isTenantShared: $isTenantShared,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    #[Test]
    public function applyAddsTenantFilterWhenScopedAndActive(): void
    {
        $this->tenantScope->method('isActive')->willReturn(true);
        $this->tenantScope->method('currentTenantId')->willReturn('tenant-abc');

        $metadata = $this->buildMetadata(isTenantScoped: true, tenantColumn: 'tenant_id');
        $builder = new SelectBuilder($this->connection)->from('entities');

        $this->applier->apply($builder, $metadata);

        $compiled = $builder->toSql();
        self::assertStringContainsString('WHERE', $compiled['sql']);
        self::assertStringContainsString('tenant_id', $compiled['sql']);
        self::assertContains('tenant-abc', $compiled['bindings']);
    }

    #[Test]
    public function applySkipsWhenEntityNotTenantScoped(): void
    {
        $this->tenantScope->method('isActive')->willReturn(true);
        $this->tenantScope->method('currentTenantId')->willReturn('tenant-abc');

        $metadata = $this->buildMetadata(isTenantScoped: false);
        $builder = new SelectBuilder($this->connection)->from('entities');

        $this->applier->apply($builder, $metadata);

        $compiled = $builder->toSql();
        self::assertStringNotContainsString('WHERE', $compiled['sql']);
    }

    #[Test]
    public function applySkipsWhenEntityIsTenantShared(): void
    {
        $this->tenantScope->method('isActive')->willReturn(true);
        $this->tenantScope->method('currentTenantId')->willReturn('tenant-abc');

        $metadata = $this->buildMetadata(isTenantScoped: true, tenantColumn: 'tenant_id', isTenantShared: true);
        $builder = new SelectBuilder($this->connection)->from('entities');

        $this->applier->apply($builder, $metadata);

        $compiled = $builder->toSql();
        self::assertStringNotContainsString('WHERE', $compiled['sql']);
    }

    #[Test]
    public function applySkipsWhenTenantScopeNotActive(): void
    {
        $this->tenantScope->method('isActive')->willReturn(false);

        $metadata = $this->buildMetadata(isTenantScoped: true, tenantColumn: 'tenant_id');
        $builder = new SelectBuilder($this->connection)->from('entities');

        $this->applier->apply($builder, $metadata);

        $compiled = $builder->toSql();
        self::assertStringNotContainsString('WHERE', $compiled['sql']);
    }

    #[Test]
    public function applySkipsWhenTenantIdIsNull(): void
    {
        $this->tenantScope->method('isActive')->willReturn(true);
        $this->tenantScope->method('currentTenantId')->willReturn(null);

        $metadata = $this->buildMetadata(isTenantScoped: true, tenantColumn: 'tenant_id');
        $builder = new SelectBuilder($this->connection)->from('entities');

        $this->applier->apply($builder, $metadata);

        $compiled = $builder->toSql();
        self::assertStringNotContainsString('WHERE', $compiled['sql']);
    }

    #[Test]
    public function applyUsesDefaultTenantColumnFromConfig(): void
    {
        $this->tenantScope->method('isActive')->willReturn(true);
        $this->tenantScope->method('currentTenantId')->willReturn('t-123');

        // tenantColumn is null on metadata, so falls back to OrmConfig default 'tenant_id'
        $metadata = $this->buildMetadata(isTenantScoped: true, tenantColumn: null);
        $builder = new SelectBuilder($this->connection)->from('entities');

        $this->applier->apply($builder, $metadata);

        $compiled = $builder->toSql();
        self::assertStringContainsString('tenant_id', $compiled['sql']);
        self::assertContains('t-123', $compiled['bindings']);
    }

    #[Test]
    public function applyUsesEntitySpecificTenantColumn(): void
    {
        $this->tenantScope->method('isActive')->willReturn(true);
        $this->tenantScope->method('currentTenantId')->willReturn('t-456');

        $metadata = $this->buildMetadata(isTenantScoped: true, tenantColumn: 'org_id');
        $builder = new SelectBuilder($this->connection)->from('entities');

        $this->applier->apply($builder, $metadata);

        $compiled = $builder->toSql();
        self::assertStringContainsString('org_id', $compiled['sql']);
        self::assertContains('t-456', $compiled['bindings']);
    }

    #[Test]
    public function applySkipsWhenColumnResolverReturnsNull(): void
    {
        $this->tenantScope->method('isActive')->willReturn(true);
        $this->tenantScope->method('currentTenantId')->willReturn('t-789');

        // Not tenant scoped, so resolver returns null
        $metadata = $this->buildMetadata(isTenantScoped: false);
        $builder = new SelectBuilder($this->connection)->from('entities');

        $this->applier->apply($builder, $metadata);

        $compiled = $builder->toSql();
        self::assertStringNotContainsString('WHERE', $compiled['sql']);
    }
}
