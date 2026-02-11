<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Tenancy\TenantColumnResolver;
use stdClass;

#[CoversClass(TenantColumnResolver::class)]
final class TenantColumnResolverTest extends TestCase
{
    private function makeMetadata(
        bool $isTenantScoped = true,
        ?string $tenantColumn = null,
    ): EntityMetadata {
        return new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'test_table',
            schema: null,
            primaryKey: new ColumnMetadata('id', 'id', ColumnType::Integer, isPrimaryKey: true),
            columns: [],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: $isTenantScoped,
            tenantColumn: $tenantColumn,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    #[Test]
    public function resolveReturnsNullForNonTenantScoped(): void
    {
        $config = OrmConfig::fromArray(['tenant_column' => 'tenant_id']);
        $resolver = new TenantColumnResolver($config);

        $metadata = $this->makeMetadata(isTenantScoped: false);

        self::assertNull($resolver->resolve($metadata));
    }

    #[Test]
    public function resolveReturnsEntityTenantColumn(): void
    {
        $config = OrmConfig::fromArray(['tenant_column' => 'tenant_id']);
        $resolver = new TenantColumnResolver($config);

        $metadata = $this->makeMetadata(isTenantScoped: true, tenantColumn: 'org_id');

        self::assertSame('org_id', $resolver->resolve($metadata));
    }

    #[Test]
    public function resolveReturnsConfigDefaultWhenNoEntityColumn(): void
    {
        $config = OrmConfig::fromArray(['tenant_column' => 'company_id']);
        $resolver = new TenantColumnResolver($config);

        $metadata = $this->makeMetadata(isTenantScoped: true, tenantColumn: null);

        self::assertSame('company_id', $resolver->resolve($metadata));
    }

    #[Test]
    public function resolveReturnsDefaultTenantColumnFromDefaults(): void
    {
        $config = OrmConfig::fromArray([]);
        $resolver = new TenantColumnResolver($config);

        $metadata = $this->makeMetadata(isTenantScoped: true, tenantColumn: null);

        self::assertSame('tenant_id', $resolver->resolve($metadata));
    }

    #[Test]
    public function resolveEntityTenantColumnOverridesConfig(): void
    {
        $config = OrmConfig::fromArray(['tenant_column' => 'global_tenant']);
        $resolver = new TenantColumnResolver($config);

        $metadata = $this->makeMetadata(isTenantScoped: true, tenantColumn: 'specific_tenant');

        self::assertSame('specific_tenant', $resolver->resolve($metadata));
    }
}
