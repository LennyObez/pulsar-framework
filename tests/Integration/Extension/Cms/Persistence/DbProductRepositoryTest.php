<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Internal\Persistence\DbProductRepository;

#[CoversClass(DbProductRepository::class)]
final class DbProductRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbProductRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_products (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                sku VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                price_amount INTEGER NOT NULL DEFAULT 0,
                price_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
                tax_category VARCHAR(50) DEFAULT NULL,
                stock_quantity INTEGER NOT NULL DEFAULT 0,
                digital INTEGER NOT NULL DEFAULT 0,
                content_id VARCHAR(36) DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $this->repository = new DbProductRepository($this->connection);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $product = Product::create(
            id: 'prod-001',
            sku: 'SKU-001',
            priceAmount: 2999,
            priceCurrency: 'USD',
            stockQuantity: 100,
        );
        $this->repository->save($product);

        $found = $this->repository->findById('prod-001');

        self::assertNotNull($found);
        self::assertSame('prod-001', $found->id);
        self::assertSame('SKU-001', $found->sku);
        self::assertSame(ProductStatus::Draft, $found->status);
        self::assertSame(2999, $found->priceAmount);
        self::assertSame('USD', $found->priceCurrency);
        self::assertSame(100, $found->stockQuantity);
        self::assertFalse($found->digital);
        self::assertTrue($found->isInStock());
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findBySkuReturnsProduct(): void
    {
        $product = Product::create(
            id: 'prod-sku',
            sku: 'UNIQUE-SKU',
            priceAmount: 1000,
            priceCurrency: 'EUR',
        );
        $this->repository->save($product);

        $found = $this->repository->findBySku('UNIQUE-SKU');

        self::assertNotNull($found);
        self::assertSame('prod-sku', $found->id);
    }

    #[Test]
    public function findBySkuReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findBySku('NONEXISTENT'));
    }

    #[Test]
    public function findByIdsReturnsMultipleProducts(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::create(
                id: "prod-multi-{$i}",
                sku: "SKU-MULTI-{$i}",
                priceAmount: $i * 1000,
                priceCurrency: 'USD',
            );
            $this->repository->save($product);
        }

        $results = $this->repository->findByIds(['prod-multi-1', 'prod-multi-3']);

        self::assertCount(2, $results);
        self::assertArrayHasKey('prod-multi-1', $results);
        self::assertArrayHasKey('prod-multi-3', $results);
    }

    #[Test]
    public function findByIdsReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], $this->repository->findByIds([]));
    }

    #[Test]
    public function findByContentIdReturnsProduct(): void
    {
        $product = Product::create(
            id: 'prod-content',
            sku: 'SKU-CONTENT',
            priceAmount: 5000,
            priceCurrency: 'USD',
            contentId: 'content-abc',
        );
        $this->repository->save($product);

        $found = $this->repository->findByContentId('content-abc');

        self::assertNotNull($found);
        self::assertSame('prod-content', $found->id);
    }

    #[Test]
    public function findByContentIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByContentId('nonexistent'));
    }

    #[Test]
    public function saveUpdatesExistingProduct(): void
    {
        $product = Product::create(
            id: 'prod-upd',
            sku: 'SKU-UPD',
            priceAmount: 1000,
            priceCurrency: 'USD',
            stockQuantity: 10,
        );
        $this->repository->save($product);

        $updated = new Product(
            id: 'prod-upd',
            tenantId: null,
            sku: 'SKU-UPD-V2',
            status: ProductStatus::Active,
            priceAmount: 1500,
            priceCurrency: 'USD',
            taxCategory: 'standard',
            stockQuantity: 50,
            digital: false,
            contentId: null,
            createdAt: $product->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
        $this->repository->save($updated);

        $found = $this->repository->findById('prod-upd');
        self::assertNotNull($found);
        self::assertSame('SKU-UPD-V2', $found->sku);
        self::assertSame(ProductStatus::Active, $found->status);
        self::assertSame(1500, $found->priceAmount);
        self::assertSame(50, $found->stockQuantity);
        self::assertSame('standard', $found->taxCategory);
    }

    #[Test]
    public function reserveStockDecrementsQuantity(): void
    {
        $product = Product::create(
            id: 'prod-rsv',
            sku: 'SKU-RSV',
            priceAmount: 1000,
            priceCurrency: 'USD',
            stockQuantity: 10,
        );
        $this->repository->save($product);

        $reserved = $this->repository->reserveStock('prod-rsv', 3);

        self::assertTrue($reserved);

        $found = $this->repository->findById('prod-rsv');
        self::assertNotNull($found);
        self::assertSame(7, $found->stockQuantity);
    }

    #[Test]
    public function reserveStockFailsWhenInsufficientStock(): void
    {
        $product = Product::create(
            id: 'prod-rsv-fail',
            sku: 'SKU-RSV-FAIL',
            priceAmount: 1000,
            priceCurrency: 'USD',
            stockQuantity: 2,
        );
        $this->repository->save($product);

        $reserved = $this->repository->reserveStock('prod-rsv-fail', 5);

        self::assertFalse($reserved);

        $found = $this->repository->findById('prod-rsv-fail');
        self::assertNotNull($found);
        self::assertSame(2, $found->stockQuantity);
    }

    #[Test]
    public function restoreStockIncrementsQuantity(): void
    {
        $product = Product::create(
            id: 'prod-rst',
            sku: 'SKU-RST',
            priceAmount: 1000,
            priceCurrency: 'USD',
            stockQuantity: 5,
        );
        $this->repository->save($product);

        $this->repository->restoreStock('prod-rst', 3);

        $found = $this->repository->findById('prod-rst');
        self::assertNotNull($found);
        self::assertSame(8, $found->stockQuantity);
    }

    #[Test]
    public function listProductsWithStatusFilter(): void
    {
        $draft = Product::create('prod-lf-1', 'SKU-LF-1', 1000, 'USD');
        $active = new Product(
            id: 'prod-lf-2',
            tenantId: null,
            sku: 'SKU-LF-2',
            status: ProductStatus::Active,
            priceAmount: 2000,
            priceCurrency: 'USD',
            taxCategory: null,
            stockQuantity: 0,
            digital: false,
            contentId: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $this->repository->save($draft);
        $this->repository->save($active);

        $results = $this->repository->listProducts(['status' => 'active'], page: 1, perPage: 10);

        self::assertCount(1, $results);
        self::assertSame('prod-lf-2', $results[0]->id);
    }

    #[Test]
    public function listProductsWithDigitalFilter(): void
    {
        $physical = Product::create('prod-d-1', 'SKU-D-1', 1000, 'USD');
        $digital = Product::create('prod-d-2', 'SKU-D-2', 500, 'USD', digital: true);

        $this->repository->save($physical);
        $this->repository->save($digital);

        $results = $this->repository->listProducts(['digital' => true], page: 1, perPage: 10);

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isDigital());
    }

    #[Test]
    public function digitalProductPersistence(): void
    {
        $product = Product::create(
            id: 'prod-dig',
            sku: 'SKU-DIG',
            priceAmount: 999,
            priceCurrency: 'USD',
            digital: true,
        );
        $this->repository->save($product);

        $found = $this->repository->findById('prod-dig');
        self::assertNotNull($found);
        self::assertTrue($found->isDigital());
        self::assertFalse($found->isInStock());
    }
}
