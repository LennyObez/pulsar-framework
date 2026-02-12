<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Config\MetadataCacheConfig;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Tenancy\TenantColumnResolver;

final class TenantColumnResolverTest extends TestCase
{
    private function createConfig(string $tenantColumn = 'tenant_id'): OrmConfig
    {
        return new OrmConfig(
            connection: 'default',
            metadataCache: new MetadataCacheConfig('array', ''),
            encryption: new EncryptionConfig(false, 5, '', ''),
            tenantColumn: $tenantColumn,
            softDeleteColumn: 'deleted_at',
        );
    }

    private function createMetadata(bool $tenantScoped, ?string $tenantColumn): EntityMetadata
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);

        return new EntityMetadata(
            entityClass: 'App\\Entity\\Foo',
            tableName: 'foos',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: $tenantScoped,
            tenantColumn: $tenantColumn,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    #[Test]
    public function returnsNullForNonTenantScopedEntity(): void
    {
        $resolver = new TenantColumnResolver($this->createConfig());
        $meta = $this->createMetadata(false, null);

        self::assertNull($resolver->resolve($meta));
    }

    #[Test]
    public function returnsEntityTenantColumnWhenSet(): void
    {
        $resolver = new TenantColumnResolver($this->createConfig());
        $meta = $this->createMetadata(true, 'org_id');

        self::assertSame('org_id', $resolver->resolve($meta));
    }

    #[Test]
    public function fallsBackToConfigTenantColumn(): void
    {
        $resolver = new TenantColumnResolver($this->createConfig('company_id'));
        $meta = $this->createMetadata(true, null);

        self::assertSame('company_id', $resolver->resolve($meta));
    }
}
