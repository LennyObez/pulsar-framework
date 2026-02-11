<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Commerce\ProductVariant;
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Internal\Commerce\CheckoutService;

#[CoversClass(CheckoutService::class)]
final class CheckoutServiceValidateCartTest extends TestCase
{
    private CheckoutService $service;
    private ProductRepositoryInterface&Stub $products;
    private ProductVariantRepositoryInterface&Stub $variants;

    protected function setUp(): void
    {
        $this->products = $this->createStub(ProductRepositoryInterface::class);
        $this->variants = $this->createStub(ProductVariantRepositoryInterface::class);

        $this->service = new CheckoutService(
            products: $this->products,
            orders: $this->createStub(OrderRepositoryInterface::class),
            orderItems: $this->createStub(OrderItemRepositoryInterface::class),
            promotions: $this->createStub(PromotionServiceInterface::class),
            taxCalculator: $this->createStub(TaxCalculatorInterface::class),
            invoiceService: $this->createStub(InvoiceServiceInterface::class),
            digitalDelivery: $this->createStub(DigitalDeliveryServiceInterface::class),
            db: $this->createStub(ConnectionInterface::class),
            config: new CommerceConfig(),
            events: $this->createStub(EventDispatcherInterface::class),
            variants: $this->variants,
        );
    }

    private function createProduct(
        string $id = 'p1',
        ProductStatus $status = ProductStatus::Active,
        int $priceAmount = 1000,
        int $stockQuantity = 10,
    ): Product {
        $now = new DateTimeImmutable();

        return new Product(
            id: $id,
            tenantId: null,
            sku: "SKU-{$id}",
            status: $status,
            priceAmount: $priceAmount,
            priceCurrency: 'EUR',
            taxCategory: null,
            stockQuantity: $stockQuantity,
            digital: false,
            contentId: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    #[Test]
    public function validateCartReturnsValidForCorrectCart(): void
    {
        $product = $this->createProduct();
        $this->products->method('findById')->willReturn($product);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 2, 'unitPrice' => 1000],
        ]);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertCount(1, $result->validatedItems);
        self::assertSame('p1', $result->validatedItems[0]['productId']);
    }

    #[Test]
    public function validateCartReturnsErrorWhenProductNotFound(): void
    {
        $this->products->method('findById')->willReturn(null);

        $result = $this->service->validateCart([
            ['productId' => 'nonexistent', 'quantity' => 1, 'unitPrice' => 1000],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('not found', $result->errors[0]);
    }

    #[Test]
    public function validateCartReturnsErrorWhenProductNotActive(): void
    {
        $product = $this->createProduct(status: ProductStatus::Draft);
        $this->products->method('findById')->willReturn($product);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 1000],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('not available', $result->errors[0]);
    }

    #[Test]
    public function validateCartReturnsErrorWhenInsufficientStock(): void
    {
        $product = $this->createProduct(stockQuantity: 3);
        $this->products->method('findById')->willReturn($product);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 5, 'unitPrice' => 1000],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Insufficient stock', $result->errors[0]);
    }

    #[Test]
    public function validateCartReturnsErrorWhenPriceChanged(): void
    {
        $product = $this->createProduct(priceAmount: 2000);
        $this->products->method('findById')->willReturn($product);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 1000],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Price changed', $result->errors[0]);
    }

    #[Test]
    public function validateCartWithVariantNotFound(): void
    {
        $product = $this->createProduct();
        $this->products->method('findById')->willReturn($product);
        $this->variants->method('findById')->willReturn(null);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 1000, 'variantId' => 'v1'],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Variant not found', $result->errors[0]);
    }

    #[Test]
    public function validateCartWithInactiveVariant(): void
    {
        $product = $this->createProduct();
        $this->products->method('findById')->willReturn($product);

        $variant = new ProductVariant(
            id: 'v1',
            productId: 'p1',
            skuSuffix: 'RED',
            attributeValues: ['color' => 'red'],
            priceModifier: 0,
            stockQuantity: 10,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: false,
        );
        $this->variants->method('findById')->willReturn($variant);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 1000, 'variantId' => 'v1'],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('not available', $result->errors[0]);
    }

    #[Test]
    public function validateCartWithVariantInsufficientStock(): void
    {
        $product = $this->createProduct(stockQuantity: 100);
        $this->products->method('findById')->willReturn($product);

        $variant = new ProductVariant(
            id: 'v1',
            productId: 'p1',
            skuSuffix: 'SM',
            attributeValues: ['size' => 'small'],
            priceModifier: 0,
            stockQuantity: 2,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: true,
        );
        $this->variants->method('findById')->willReturn($variant);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 5, 'unitPrice' => 1000, 'variantId' => 'v1'],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Insufficient stock', $result->errors[0]);
    }

    #[Test]
    public function validateCartWithVariantPriceModifier(): void
    {
        $product = $this->createProduct(priceAmount: 1000);
        $this->products->method('findById')->willReturn($product);

        $variant = new ProductVariant(
            id: 'v1',
            productId: 'p1',
            skuSuffix: 'LG',
            attributeValues: ['size' => 'large'],
            priceModifier: 500,
            stockQuantity: 10,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: true,
        );
        $this->variants->method('findById')->willReturn($variant);

        // Effective price = 1000 + 500 = 1500, but cart says 1000
        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 1000, 'variantId' => 'v1'],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Price changed', $result->errors[0]);
    }

    #[Test]
    public function validateCartWithVariantCorrectPrice(): void
    {
        $product = $this->createProduct(priceAmount: 1000);
        $this->products->method('findById')->willReturn($product);

        $variant = new ProductVariant(
            id: 'v1',
            productId: 'p1',
            skuSuffix: 'LG',
            attributeValues: ['size' => 'large'],
            priceModifier: 500,
            stockQuantity: 10,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: true,
        );
        $this->variants->method('findById')->willReturn($variant);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 1500, 'variantId' => 'v1'],
        ]);

        self::assertTrue($result->isValid);
        /** @var array<string, mixed> $item */
        $item = $result->validatedItems[0];
        self::assertArrayHasKey('variantId', $item);
        self::assertSame('v1', $item['variantId']);
    }

    #[Test]
    public function validateCartAllowsUnlimitedStockWhenZero(): void
    {
        // stockQuantity 0 means unlimited
        $product = $this->createProduct(stockQuantity: 0);
        $this->products->method('findById')->willReturn($product);

        $result = $this->service->validateCart([
            ['productId' => 'p1', 'quantity' => 999, 'unitPrice' => 1000],
        ]);

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateCartCollectsMultipleErrors(): void
    {
        $this->products->method('findById')->willReturn(null);

        $result = $this->service->validateCart([
            ['productId' => 'missing1', 'quantity' => 1, 'unitPrice' => 100],
            ['productId' => 'missing2', 'quantity' => 1, 'unitPrice' => 200],
        ]);

        self::assertFalse($result->isValid);
        self::assertCount(2, $result->errors);
    }
}
