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
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Internal\Persistence\DbProductRepository;

#[CoversClass(DbProductRepository::class)]
final class DbProductRepositoryTest extends TestCase
{
    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([]));

        $repo = new DbProductRepository($db);

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function findByIdReturnsHydratedProduct(): void
    {
        $row = $this->createProductRow([
            'id' => 'prod-1',
            'tenant_id' => null,
            'sku' => 'SKU-001',
            'status' => 'active',
            'price_amount' => 2999,
            'price_currency' => 'USD',
            'tax_category' => 'standard',
            'stock_quantity' => 100,
            'digital' => 0,
            'content_id' => 'content-1',
            'created_at' => '2024-06-15T10:00:00+00:00',
            'updated_at' => '2024-06-16T12:00:00+00:00',
        ]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbProductRepository($db);
        $product = $repo->findById('prod-1');

        self::assertNotNull($product);
        self::assertSame('prod-1', $product->id);
        self::assertSame('SKU-001', $product->sku);
        self::assertSame(ProductStatus::Active, $product->status);
        self::assertSame(2999, $product->priceAmount);
        self::assertSame('USD', $product->priceCurrency);
        self::assertSame('standard', $product->taxCategory);
        self::assertSame(100, $product->stockQuantity);
        self::assertFalse($product->digital);
        self::assertSame('content-1', $product->contentId);
    }

    #[Test]
    public function findByIdWithDigitalProductSetsDigitalFlag(): void
    {
        $row = $this->createProductRow([
            'id' => 'prod-digital',
            'tenant_id' => null,
            'sku' => 'DIGITAL-001',
            'status' => 'active',
            'price_amount' => 999,
            'price_currency' => 'USD',
            'tax_category' => null,
            'stock_quantity' => 0,
            'digital' => 1,
            'content_id' => null,
            'created_at' => '2024-06-15T10:00:00+00:00',
            'updated_at' => '2024-06-15T10:00:00+00:00',
        ]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbProductRepository($db);
        $product = $repo->findById('prod-digital');

        self::assertNotNull($product);
        self::assertTrue($product->digital);
    }

    #[Test]
    public function findByIdsReturnsEmptyForEmptyInput(): void
    {
        $db = $this->createStub(ConnectionInterface::class);

        $repo = new DbProductRepository($db);
        $results = $repo->findByIds([]);

        self::assertSame([], $results);
    }

    #[Test]
    public function findByIdsReturnsProductsKeyedById(): void
    {
        $rows = [
            $this->createProductRow($this->defaultProductData('prod-a', 'SKU-A')),
            $this->createProductRow($this->defaultProductData('prod-b', 'SKU-B')),
        ];

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result($rows));

        $repo = new DbProductRepository($db);
        $results = $repo->findByIds(['prod-a', 'prod-b']);

        self::assertCount(2, $results);
        self::assertArrayHasKey('prod-a', $results);
        self::assertArrayHasKey('prod-b', $results);
        self::assertSame('SKU-A', $results['prod-a']->sku);
    }

    #[Test]
    public function findByContentIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([]));

        $repo = new DbProductRepository($db);

        self::assertNull($repo->findByContentId('nonexistent'));
    }

    #[Test]
    public function findByContentIdReturnsProduct(): void
    {
        $row = $this->createProductRow($this->defaultProductData('prod-content', 'SKU-C'));

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbProductRepository($db);
        $product = $repo->findByContentId('content-1');

        self::assertNotNull($product);
        self::assertSame('prod-content', $product->id);
    }

    #[Test]
    public function findByContentIdAppliesTenantScope(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('tenant_id'),
                self::callback(static fn(array $b): bool => $b['tenant_id'] === 'my-tenant'),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db, tenantId: 'my-tenant');
        $repo->findByContentId('c-1');
    }

    #[Test]
    public function findBySkuReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([]));

        $repo = new DbProductRepository($db);

        self::assertNull($repo->findBySku('NONEXISTENT'));
    }

    #[Test]
    public function findBySkuReturnsProduct(): void
    {
        $row = $this->createProductRow($this->defaultProductData('prod-sku', 'UNIQUE-SKU'));

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbProductRepository($db);
        $product = $repo->findBySku('UNIQUE-SKU');

        self::assertNotNull($product);
        self::assertSame('UNIQUE-SKU', $product->sku);
    }

    #[Test]
    public function findBySkuUsesExplicitTenantOverride(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('tenant_id'),
                self::callback(static fn(array $b): bool => $b['tenant_id'] === 'explicit'),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db, tenantId: 'default');
        $repo->findBySku('SKU', tenantId: 'explicit');
    }

    #[Test]
    public function listProductsReturnsResults(): void
    {
        $rows = [
            $this->createProductRow($this->defaultProductData('prod-list-1', 'SKU-1')),
        ];

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result($rows));

        $repo = new DbProductRepository($db);
        $results = $repo->listProducts([], 1, 10);

        self::assertCount(1, $results);
    }

    #[Test]
    public function listProductsAppliesStatusFilter(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('status = :status'),
                self::callback(static fn(array $b): bool => $b['status'] === 'active'),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db);
        $repo->listProducts(['status' => 'active'], 1, 10);
    }

    #[Test]
    public function listProductsAppliesDigitalFilter(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('digital = :digital'),
                self::callback(static fn(array $b): bool => $b['digital'] === 1),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db);
        $repo->listProducts(['digital' => true], 1, 10);
    }

    #[Test]
    public function listProductsCalculatesPagination(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(static fn(array $b): bool => $b['limit'] === 25 && $b['offset'] === 50),
            )
            ->willReturn(new Result([]));

        $repo = new DbProductRepository($db);
        $repo->listProducts([], 3, 25);
    }

    #[Test]
    public function saveCallsExecuteWithCorrectBindings(): void
    {
        $now = new DateTimeImmutable('2024-06-15T10:00:00+00:00');
        $product = new Product(
            id: 'prod-save',
            tenantId: 'tenant-1',
            sku: 'SAVE-SKU',
            status: ProductStatus::Draft,
            priceAmount: 5000,
            priceCurrency: 'EUR',
            taxCategory: 'reduced',
            stockQuantity: 50,
            digital: false,
            contentId: 'content-1',
            createdAt: $now,
            updatedAt: $now,
        );

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO cms_products'),
                self::callback(static function (array $b): bool {
                    return $b['id'] === 'prod-save'
                        && $b['sku'] === 'SAVE-SKU'
                        && $b['status'] === 'draft'
                        && $b['price_amount'] === 5000
                        && $b['price_currency'] === 'EUR'
                        && $b['digital'] === 0;
                }),
            );

        $repo = new DbProductRepository($db);
        $repo->save($product);
    }

    #[Test]
    public function saveSerializesDigitalAsTruthy(): void
    {
        $now = new DateTimeImmutable();
        $product = new Product(
            id: 'prod-dig',
            tenantId: null,
            sku: 'DIG-001',
            status: ProductStatus::Active,
            priceAmount: 999,
            priceCurrency: 'USD',
            taxCategory: null,
            stockQuantity: 0,
            digital: true,
            contentId: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $b): bool => $b['digital'] === 1),
            );

        $repo = new DbProductRepository($db);
        $repo->save($product);
    }

    #[Test]
    public function reserveStockReturnsTrueWhenSufficientStock(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('execute')->willReturn(1);

        $repo = new DbProductRepository($db);
        $result = $repo->reserveStock('prod-1', 5);

        self::assertTrue($result);
    }

    #[Test]
    public function reserveStockReturnsFalseWhenInsufficientStock(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('execute')->willReturn(0);

        $repo = new DbProductRepository($db);
        $result = $repo->reserveStock('prod-1', 999);

        self::assertFalse($result);
    }

    #[Test]
    public function restoreStockCallsExecute(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('stock_quantity + :qty'),
                self::callback(static fn(array $b): bool => $b['qty'] === 10 && $b['id'] === 'prod-1'),
            );

        $repo = new DbProductRepository($db);
        $repo->restoreStock('prod-1', 10);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultProductData(string $id = 'prod-1', string $sku = 'SKU-001'): array
    {
        return [
            'id' => $id,
            'tenant_id' => null,
            'sku' => $sku,
            'status' => 'active',
            'price_amount' => 2999,
            'price_currency' => 'USD',
            'tax_category' => null,
            'stock_quantity' => 100,
            'digital' => 0,
            'content_id' => null,
            'created_at' => '2024-06-15T10:00:00+00:00',
            'updated_at' => '2024-06-15T10:00:00+00:00',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createProductRow(array $data): Row
    {
        return new Row($data);
    }
}
