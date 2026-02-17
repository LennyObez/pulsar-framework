<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Tenancy\TenantColumnResolver;
use Pulsar\Extension\Orm\Features\Tenancy\TenantInsertEnricher;
use stdClass;

final class TenantInsertEnricherTest extends TestCase
{
    private TenantScopeInterface&Stub $scope;
    private TenantColumnResolver $resolver;
    private TenantInsertEnricher $enricher;

    protected function setUp(): void
    {
        $this->scope = $this->createStub(TenantScopeInterface::class);
        $this->resolver = new TenantColumnResolver(OrmConfig::fromArray(['tenant_column' => 'tenant_id']));
        $this->enricher = new TenantInsertEnricher($this->scope, $this->resolver);
    }

    #[Test]
    public function enrichAddsTenantColumnWhenScopedAndActive(): void
    {
        $this->scope->method('isActive')->willReturn(true);
        $this->scope->method('currentTenantId')->willReturn('tenant-42');

        $metadata = $this->makeMetadata(isTenantScoped: true, tenantColumn: 'tenant_id');

        $values = $this->enricher->enrich(['name' => 'Test'], $metadata);

        self::assertSame('tenant-42', $values['tenant_id']);
    }

    #[Test]
    public function enrichSkipsWhenNotTenantScoped(): void
    {
        $metadata = $this->makeMetadata(isTenantScoped: false);

        $values = $this->enricher->enrich(['name' => 'Test'], $metadata);

        self::assertArrayNotHasKey('tenant_id', $values);
    }

    #[Test]
    public function enrichSkipsWhenTenantShared(): void
    {
        $metadata = $this->makeMetadata(isTenantScoped: true, isTenantShared: true);

        $values = $this->enricher->enrich(['name' => 'Test'], $metadata);

        self::assertArrayNotHasKey('tenant_id', $values);
    }

    #[Test]
    public function enrichSkipsWhenScopeNotActive(): void
    {
        $this->scope->method('isActive')->willReturn(false);

        $metadata = $this->makeMetadata(isTenantScoped: true, tenantColumn: 'tenant_id');

        $values = $this->enricher->enrich(['name' => 'Test'], $metadata);

        self::assertArrayNotHasKey('tenant_id', $values);
    }

    #[Test]
    public function enrichPreservesExistingTenantColumn(): void
    {
        $this->scope->method('isActive')->willReturn(true);
        $this->scope->method('currentTenantId')->willReturn('tenant-99');

        $metadata = $this->makeMetadata(isTenantScoped: true, tenantColumn: 'tenant_id');

        $values = $this->enricher->enrich(['name' => 'Test', 'tenant_id' => 'existing'], $metadata);

        self::assertSame('existing', $values['tenant_id']);
    }

    private function makeMetadata(
        bool $isTenantScoped = false,
        bool $isTenantShared = false,
        ?string $tenantColumn = null,
    ): EntityMetadata {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            nullable: false,
            isPrimaryKey: true,
            autoIncrement: true,
        );

        return new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'test_entities',
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
}
