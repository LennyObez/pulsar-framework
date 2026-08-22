<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductStatus;

#[CoversClass(Product::class)]
final class ProductTest extends TestCase
{
    #[Test]
    public function createReturnsDraftProduct(): void
    {
        $product = Product::create(
            id: 'p1',
            sku: 'WIDGET-001',
            priceAmount: 1999,
            priceCurrency: 'USD',
        );

        self::assertSame('p1', $product->id);
        self::assertSame('WIDGET-001', $product->sku);
        self::assertSame(ProductStatus::Draft, $product->status);
        self::assertSame(1999, $product->priceAmount);
        self::assertSame('USD', $product->priceCurrency);
        self::assertNull($product->tenantId);
        self::assertNull($product->taxCategory);
        self::assertSame(0, $product->stockQuantity);
        self::assertFalse($product->digital);
        self::assertNull($product->contentId);
    }

    #[Test]
    public function createWithAllParameters(): void
    {
        $product = Product::create(
            id: 'p2',
            sku: 'EBOOK-001',
            priceAmount: 999,
            priceCurrency: 'EUR',
            tenantId: 'tenant-01',
            taxCategory: 'digital_goods',
            stockQuantity: 50,
            digital: true,
            contentId: 'content-01',
        );

        self::assertSame('tenant-01', $product->tenantId);
        self::assertSame('digital_goods', $product->taxCategory);
        self::assertSame(50, $product->stockQuantity);
        self::assertTrue($product->digital);
        self::assertSame('content-01', $product->contentId);
    }

    #[Test]
    public function isActiveReturnsTrueForActiveStatus(): void
    {
        $product = $this->createWithStatus(ProductStatus::Active);

        self::assertTrue($product->isActive());
    }

    #[Test]
    public function isActiveReturnsFalseForDraft(): void
    {
        $product = $this->createWithStatus(ProductStatus::Draft);

        self::assertFalse($product->isActive());
    }

    #[Test]
    public function isActiveReturnsFalseForArchived(): void
    {
        $product = $this->createWithStatus(ProductStatus::Archived);

        self::assertFalse($product->isActive());
    }

    #[Test]
    public function isDigitalReturnsCorrectValue(): void
    {
        $digital = Product::create('p1', 'D-001', 500, 'USD', digital: true);
        $physical = Product::create('p2', 'P-001', 500, 'USD', digital: false);

        self::assertTrue($digital->isDigital());
        self::assertFalse($physical->isDigital());
    }

    #[Test]
    public function isInStockReturnsCorrectValue(): void
    {
        $inStock = Product::create('p1', 'S-001', 500, 'USD', stockQuantity: 5);
        $outOfStock = Product::create('p2', 'S-002', 500, 'USD', stockQuantity: 0);

        self::assertTrue($inStock->isInStock());
        self::assertFalse($outOfStock->isInStock());
    }

    private function createWithStatus(ProductStatus $status): Product
    {
        $now = new DateTimeImmutable();

        return new Product(
            id: 'p1',
            tenantId: null,
            sku: 'TEST',
            status: $status,
            priceAmount: 1000,
            priceCurrency: 'EUR',
            taxCategory: null,
            stockQuantity: 10,
            digital: false,
            contentId: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
