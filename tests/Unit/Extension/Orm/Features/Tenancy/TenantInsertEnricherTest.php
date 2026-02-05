<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
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

#[CoversClass(TenantInsertEnricher::class)]
final class TenantInsertEnricherTest extends TestCase
{
    private function makeMetadata(
        bool $isTenantScoped = true,
        bool $isTenantShared = false,
        ?string $tenantColumn = null,
    ): EntityMetadata {
        return new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'orders',
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
            isTenantShared: $isTenantShared,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    private function makeConfig(): OrmConfig
    {
        return OrmConfig::fromArray(['tenant_column' => 'tenant_id']);
    }

    #[Test]
    public function enrichAddsTenantIdToValues(): void
    {
        $scope = $this->createStub(TenantScopeInterface::class);
        $scope->method('isActive')->willReturn(true);
        $scope->method('currentTenantId')->willReturn('tenant_abc');

        $resolver = new TenantColumnResolver($this->makeConfig());
        $enricher = new TenantInsertEnricher($scope, $resolver);

        $metadata = $this->makeMetadata(isTenantScoped: true);
        $values = ['name' => 'Order #1', 'total' => 100];

        $result = $enricher->enrich($values, $metadata);

        self::assertArrayHasKey('tenant_id', $result);
        self::assertSame('tenant_abc', $result['tenant_id']);
        self::assertSame('Order #1', $result['name']);
    }

    #[Test]
    public function enrichSkipsNonTenantScopedEntity(): void
    {
        $scope = $this->createStub(TenantScopeInterface::class);
        $scope->method('isActive')->willReturn(true);
        $scope->method('currentTenantId')->willReturn('tenant_abc');

        $resolver = new TenantColumnResolver($this->makeConfig());
        $enricher = new TenantInsertEnricher($scope, $resolver);

        $metadata = $this->makeMetadata(isTenantScoped: false);
        $values = ['name' => 'System Setting'];

        $result = $enricher->enrich($values, $metadata);

        self::assertArrayNotHasKey('tenant_id', $result);
    }

    #[Test]
    public function enrichSkipsTenantSharedEntity(): void
    {
        $scope = $this->createStub(TenantScopeInterface::class);
        $scope->method('isActive')->willReturn(true);
        $scope->method('currentTenantId')->willReturn('tenant_abc');

        $resolver = new TenantColumnResolver($this->makeConfig());
        $enricher = new TenantInsertEnricher($scope, $resolver);

        $metadata = $this->makeMetadata(isTenantScoped: true, isTenantShared: true);
        $values = ['name' => 'Shared Resource'];

        $result = $enricher->enrich($values, $metadata);

        self::assertArrayNotHasKey('tenant_id', $result);
    }

    #[Test]
    public function enrichSkipsWhenScopeNotActive(): void
    {
        $scope = $this->createStub(TenantScopeInterface::class);
        $scope->method('isActive')->willReturn(false);

        $resolver = new TenantColumnResolver($this->makeConfig());
        $enricher = new TenantInsertEnricher($scope, $resolver);

        $metadata = $this->makeMetadata(isTenantScoped: true);
        $values = ['name' => 'Order'];

        $result = $enricher->enrich($values, $metadata);

        self::assertArrayNotHasKey('tenant_id', $result);
    }

    #[Test]
    public function enrichSkipsWhenTenantIdIsNull(): void
    {
        $scope = $this->createStub(TenantScopeInterface::class);
        $scope->method('isActive')->willReturn(true);
        $scope->method('currentTenantId')->willReturn(null);

        $resolver = new TenantColumnResolver($this->makeConfig());
        $enricher = new TenantInsertEnricher($scope, $resolver);

        $metadata = $this->makeMetadata(isTenantScoped: true);
        $values = ['name' => 'Order'];

        $result = $enricher->enrich($values, $metadata);

        self::assertArrayNotHasKey('tenant_id', $result);
    }

    #[Test]
    public function enrichDoesNotOverrideExistingTenantColumn(): void
    {
        $scope = $this->createStub(TenantScopeInterface::class);
        $scope->method('isActive')->willReturn(true);
        $scope->method('currentTenantId')->willReturn('tenant_new');

        $resolver = new TenantColumnResolver($this->makeConfig());
        $enricher = new TenantInsertEnricher($scope, $resolver);

        $metadata = $this->makeMetadata(isTenantScoped: true);
        $values = ['name' => 'Order', 'tenant_id' => 'tenant_existing'];

        $result = $enricher->enrich($values, $metadata);

        self::assertSame('tenant_existing', $result['tenant_id']);
    }

    #[Test]
    public function enrichUsesCustomTenantColumn(): void
    {
        $scope = $this->createStub(TenantScopeInterface::class);
        $scope->method('isActive')->willReturn(true);
        $scope->method('currentTenantId')->willReturn('org_123');

        $resolver = new TenantColumnResolver($this->makeConfig());
        $enricher = new TenantInsertEnricher($scope, $resolver);

        $metadata = $this->makeMetadata(isTenantScoped: true, tenantColumn: 'organization_id');
        $values = ['name' => 'Document'];

        $result = $enricher->enrich($values, $metadata);

        self::assertArrayHasKey('organization_id', $result);
        self::assertSame('org_123', $result['organization_id']);
    }
}
