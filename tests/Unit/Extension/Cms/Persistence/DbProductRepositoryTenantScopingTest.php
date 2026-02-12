<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Internal\Persistence\DbProductRepository;

#[CoversClass(DbProductRepository::class)]
final class DbProductRepositoryTenantScopingTest extends TestCase
{
    private const string SENTINEL = '00000000-0000-0000-0000-000000000000';

    #[Test]
    public function find_by_id_uses_tenant_id_when_set(): void
    {
        $tenantId = 'tenant-abc';
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->equalTo(['id' => 'prod-1', 'tenant_key' => $tenantId]),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db, $tenantId);
        $repo->findById('prod-1');
    }

    #[Test]
    public function find_by_id_uses_sentinel_when_tenant_id_is_null(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->equalTo(['id' => 'prod-1', 'tenant_key' => self::SENTINEL]),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db);
        $repo->findById('prod-1');
    }

    #[Test]
    public function find_by_id_returns_product_when_found(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([self::productRow()]));

        $repo = new DbProductRepository($db, 'tenant-abc');
        $product = $repo->findById('prod-1');

        self::assertNotNull($product);
        self::assertSame('prod-1', $product->id);
    }

    #[Test]
    public function find_by_ids_uses_tenant_id_when_set(): void
    {
        $tenantId = 'tenant-abc';
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('COALESCE'),
                $this->callback(static function (array $bindings) use ($tenantId): bool {
                    return $bindings['tenant_key'] === $tenantId
                        && $bindings['id_0'] === 'prod-1'
                        && $bindings['id_1'] === 'prod-2';
                }),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db, $tenantId);
        $repo->findByIds(['prod-1', 'prod-2']);
    }

    #[Test]
    public function find_by_ids_uses_sentinel_when_tenant_id_is_null(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('COALESCE'),
                $this->callback(static function (array $bindings): bool {
                    return $bindings['tenant_key'] === '00000000-0000-0000-0000-000000000000'
                        && $bindings['id_0'] === 'prod-1';
                }),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db);
        $repo->findByIds(['prod-1']);
    }

    #[Test]
    public function find_by_ids_returns_empty_array_for_empty_input(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $repo = new DbProductRepository($db, 'tenant-abc');

        self::assertSame([], $repo->findByIds([]));
    }

    private static function productRow(): Row
    {
        $now = new DateTimeImmutable();

        return new Row([
            'id' => 'prod-1',
            'tenant_id' => 'tenant-abc',
            'sku' => 'SKU-001',
            'status' => ProductStatus::Active->value,
            'price_amount' => 1999,
            'price_currency' => 'USD',
            'tax_category' => null,
            'stock_quantity' => 10,
            'digital' => 0,
            'content_id' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
