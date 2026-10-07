<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductStatus;

#[CoversClass(Product::class)]
final class ProductTest extends TestCase
{
    #[Test]
    public function create_returns_draft_product_with_defaults(): void
    {
        $product = Product::create(
            id: 'prod-1',
            sku: 'SKU-001',
            priceAmount: 2999,
            priceCurrency: 'USD',
        );

        self::assertSame('prod-1', $product->id);
        self::assertSame('SKU-001', $product->sku);
        self::assertSame(ProductStatus::Draft, $product->status);
        self::assertSame(2999, $product->priceAmount);
        self::assertSame('USD', $product->priceCurrency);
        self::assertNull($product->tenantId);
        self::assertNull($product->taxCategory);
        self::assertSame(0, $product->stockQuantity);
        self::assertFalse($product->digital);
        self::assertNull($product->contentId);
    }

    #[Test]
    public function create_with_all_optional_params(): void
    {
        $product = Product::create(
            id: 'prod-2',
            sku: 'SKU-002',
            priceAmount: 5000,
            priceCurrency: 'EUR',
            tenantId: 'tenant-1',
            taxCategory: 'standard',
            stockQuantity: 50,
            digital: true,
            contentId: 'content-1',
        );

        self::assertSame('tenant-1', $product->tenantId);
        self::assertSame('standard', $product->taxCategory);
        self::assertSame(50, $product->stockQuantity);
        self::assertTrue($product->digital);
        self::assertSame('content-1', $product->contentId);
    }

    #[Test]
    public function isActive_returns_false_for_draft(): void
    {
        $product = Product::create('p1', 'SKU-1', 1000, 'USD');

        self::assertFalse($product->isActive());
    }

    #[Test]
    public function isDigital_reflects_digital_flag(): void
    {
        $physical = Product::create('p1', 'SKU-1', 1000, 'USD', digital: false);
        $digital = Product::create('p2', 'SKU-2', 500, 'USD', digital: true);

        self::assertFalse($physical->isDigital());
        self::assertTrue($digital->isDigital());
    }

    #[Test]
    public function isInStock_based_on_stock_quantity(): void
    {
        $inStock = Product::create('p1', 'SKU-1', 1000, 'USD', stockQuantity: 5);
        $outOfStock = Product::create('p2', 'SKU-2', 1000, 'USD', stockQuantity: 0);

        self::assertTrue($inStock->isInStock());
        self::assertFalse($outOfStock->isInStock());
    }
}
