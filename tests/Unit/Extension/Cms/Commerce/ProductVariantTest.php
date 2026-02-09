<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductVariant;

#[CoversClass(ProductVariant::class)]
final class ProductVariantTest extends TestCase
{
    private const string PRODUCT_ID = '019a0000-0000-7000-8000-000000000001';
    private const string VARIANT_ID = '019a0000-0000-7000-8000-000000000002';

    // ── SKU composition ─────────────────────────────────────────────

    #[Test]
    public function test_sku_composition(): void
    {
        $product = Product::create(
            id: self::PRODUCT_ID,
            sku: 'TSHIRT-001',
            priceAmount: 2999,
            priceCurrency: 'EUR',
        );

        $variant = new ProductVariant(
            id: self::VARIANT_ID,
            productId: self::PRODUCT_ID,
            skuSuffix: 'LG-BLK',
            attributeValues: ['size' => 'large', 'color' => 'black'],
            priceModifier: 0,
            stockQuantity: 50,
            mediaAssetId: null,
            sortOrder: 1,
            isActive: true,
        );

        $composedSku = $product->sku . '-' . $variant->skuSuffix;

        self::assertSame('TSHIRT-001-LG-BLK', $composedSku);
    }

    // ── Price computation (base + modifier) ─────────────────────────

    #[Test]
    public function test_price_with_positive_modifier(): void
    {
        $product = Product::create(
            id: self::PRODUCT_ID,
            sku: 'SHOE-001',
            priceAmount: 9999,
            priceCurrency: 'EUR',
        );

        $variant = new ProductVariant(
            id: self::VARIANT_ID,
            productId: self::PRODUCT_ID,
            skuSuffix: 'XL',
            attributeValues: ['size' => 'xl'],
            priceModifier: 500,
            stockQuantity: 10,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: true,
        );

        $effectivePrice = $product->priceAmount + $variant->priceModifier;

        self::assertSame(10499, $effectivePrice);
    }

    #[Test]
    public function test_price_with_negative_modifier(): void
    {
        $product = Product::create(
            id: self::PRODUCT_ID,
            sku: 'SHOE-001',
            priceAmount: 9999,
            priceCurrency: 'EUR',
        );

        $variant = new ProductVariant(
            id: self::VARIANT_ID,
            productId: self::PRODUCT_ID,
            skuSuffix: 'SM',
            attributeValues: ['size' => 'small'],
            priceModifier: -200,
            stockQuantity: 25,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: true,
        );

        $effectivePrice = $product->priceAmount + $variant->priceModifier;

        self::assertSame(9799, $effectivePrice);
    }

    #[Test]
    public function test_price_with_zero_modifier(): void
    {
        $product = Product::create(
            id: self::PRODUCT_ID,
            sku: 'SHOE-001',
            priceAmount: 9999,
            priceCurrency: 'EUR',
        );

        $variant = new ProductVariant(
            id: self::VARIANT_ID,
            productId: self::PRODUCT_ID,
            skuSuffix: 'MD',
            attributeValues: ['size' => 'medium'],
            priceModifier: 0,
            stockQuantity: 100,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: true,
        );

        $effectivePrice = $product->priceAmount + $variant->priceModifier;

        self::assertSame(9999, $effectivePrice);
    }

    // ── Entity creation ─────────────────────────────────────────────

    #[Test]
    public function test_variant_entity_creation(): void
    {
        $variant = new ProductVariant(
            id: self::VARIANT_ID,
            productId: self::PRODUCT_ID,
            skuSuffix: 'LG-RED',
            attributeValues: ['size' => 'large', 'color' => 'red'],
            priceModifier: 300,
            stockQuantity: 42,
            mediaAssetId: '019a0000-0000-7000-8000-000000000099',
            sortOrder: 3,
            isActive: true,
        );

        self::assertSame(self::VARIANT_ID, $variant->id);
        self::assertSame(self::PRODUCT_ID, $variant->productId);
        self::assertSame('LG-RED', $variant->skuSuffix);
        self::assertSame(['size' => 'large', 'color' => 'red'], $variant->attributeValues);
        self::assertSame(300, $variant->priceModifier);
        self::assertSame(42, $variant->stockQuantity);
        self::assertSame('019a0000-0000-7000-8000-000000000099', $variant->mediaAssetId);
        self::assertSame(3, $variant->sortOrder);
        self::assertTrue($variant->isActive);
    }

    #[Test]
    public function test_inactive_variant(): void
    {
        $variant = new ProductVariant(
            id: self::VARIANT_ID,
            productId: self::PRODUCT_ID,
            skuSuffix: 'DISC',
            attributeValues: [],
            priceModifier: 0,
            stockQuantity: 0,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: false,
        );

        self::assertFalse($variant->isActive);
    }
}
