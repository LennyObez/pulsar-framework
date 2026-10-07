<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\Invoice;
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
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Internal\Commerce\CheckoutService;

/**
 * Verifies that product variant pricing is correctly applied during checkout:
 * effective price = product.priceAmount + variant.priceModifier, and variant
 * stock is reserved instead of product stock when a variant is specified.
 */
#[CoversClass(CheckoutService::class)]
final class VariantCheckoutTest extends TestCase
{
    private const string PRODUCT_ID = 'prod-001';
    private const string VARIANT_ID = 'var-001';

    #[Test]
    public function checkout_with_variant_computes_effective_price(): void
    {
        $product = $this->buildProduct(priceAmount: 5000);
        $variant = $this->buildVariant(priceModifier: 1500, stockQuantity: 10);

        $effectivePrice = $product->priceAmount + $variant->priceModifier; // 6500

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findByIds')->willReturn([self::PRODUCT_ID => $product]);

        $variantRepo = $this->createStub(ProductVariantRepositoryInterface::class);
        $variantRepo->method('findByIds')->willReturn([self::VARIANT_ID => $variant]);
        $variantRepo->method('reserveStock')->willReturn(true);

        $service = $this->buildCheckoutService($productRepo, $variantRepo);

        $order = $service->createOrder(
            cartItems: [
                [
                    'productId' => self::PRODUCT_ID,
                    'variantId' => self::VARIANT_ID,
                    'quantity' => 2,
                    'unitPrice' => $effectivePrice,
                ],
            ],
            customerEmail: 'variant@example.com',
            billingAddress: self::billingAddress(),
        );

        self::assertSame($effectivePrice * 2, $order->subtotal);
    }

    #[Test]
    public function checkout_with_variant_reserves_variant_stock_not_product_stock(): void
    {
        $product = $this->buildProduct(priceAmount: 3000);
        $variant = $this->buildVariant(priceModifier: 500, stockQuantity: 5);

        $effectivePrice = $product->priceAmount + $variant->priceModifier;

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findByIds')->willReturn([self::PRODUCT_ID => $product]);
        $productRepo->method('reserveStock')->willReturn(true);

        $variantRepo = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepo->method('findByIds')->willReturn([self::VARIANT_ID => $variant]);

        $variantRepo->expects(self::once())
            ->method('reserveStock')
            ->with(self::VARIANT_ID, 1)
            ->willReturn(true);

        $service = $this->buildCheckoutService($productRepo, $variantRepo);

        $service->createOrder(
            cartItems: [
                [
                    'productId' => self::PRODUCT_ID,
                    'variantId' => self::VARIANT_ID,
                    'quantity' => 1,
                    'unitPrice' => $effectivePrice,
                ],
            ],
            customerEmail: 'stock@example.com',
            billingAddress: self::billingAddress(),
        );
    }

    #[Test]
    public function checkout_without_variant_continues_to_work(): void
    {
        $product = $this->buildProduct(priceAmount: 2000);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findByIds')->willReturn([self::PRODUCT_ID => $product]);
        $productRepo->method('reserveStock')->willReturn(true);

        $variantRepo = $this->createStub(ProductVariantRepositoryInterface::class);

        $service = $this->buildCheckoutService($productRepo, $variantRepo);

        $order = $service->createOrder(
            cartItems: [
                [
                    'productId' => self::PRODUCT_ID,
                    'quantity' => 3,
                    'unitPrice' => 2000,
                ],
            ],
            customerEmail: 'novariant@example.com',
            billingAddress: self::billingAddress(),
        );

        self::assertSame(6000, $order->subtotal);
    }

    #[Test]
    public function validate_cart_with_variant_checks_variant_price(): void
    {
        $product = $this->buildProduct(priceAmount: 1000);
        $variant = $this->buildVariant(priceModifier: 200, stockQuantity: 50);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findById')->willReturn($product);

        $variantRepo = $this->createStub(ProductVariantRepositoryInterface::class);
        $variantRepo->method('findById')->willReturn($variant);

        $service = $this->buildCheckoutService($productRepo, $variantRepo);

        // Correct effective price
        $result = $service->validateCart([
            [
                'productId' => self::PRODUCT_ID,
                'variantId' => self::VARIANT_ID,
                'quantity' => 1,
                'unitPrice' => 1200,
            ],
        ]);

        self::assertTrue($result->isValid);
        self::assertCount(1, $result->validatedItems);
        self::assertSame(1200, $result->validatedItems[0]['unitPrice']);
    }

    #[Test]
    public function validate_cart_with_variant_detects_price_mismatch(): void
    {
        $product = $this->buildProduct(priceAmount: 1000);
        $variant = $this->buildVariant(priceModifier: 200, stockQuantity: 50);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findById')->willReturn($product);

        $variantRepo = $this->createStub(ProductVariantRepositoryInterface::class);
        $variantRepo->method('findById')->willReturn($variant);

        $service = $this->buildCheckoutService($productRepo, $variantRepo);

        // Wrong price (base price without modifier)
        $result = $service->validateCart([
            [
                'productId' => self::PRODUCT_ID,
                'variantId' => self::VARIANT_ID,
                'quantity' => 1,
                'unitPrice' => 1000,
            ],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Price changed', $result->errors[0]);
    }

    #[Test]
    public function validate_cart_with_variant_checks_variant_stock(): void
    {
        $product = $this->buildProduct(priceAmount: 1000, stockQuantity: 100);
        $variant = $this->buildVariant(priceModifier: 0, stockQuantity: 2);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findById')->willReturn($product);

        $variantRepo = $this->createStub(ProductVariantRepositoryInterface::class);
        $variantRepo->method('findById')->willReturn($variant);

        $service = $this->buildCheckoutService($productRepo, $variantRepo);

        $result = $service->validateCart([
            [
                'productId' => self::PRODUCT_ID,
                'variantId' => self::VARIANT_ID,
                'quantity' => 5,
                'unitPrice' => 1000,
            ],
        ]);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Insufficient stock', $result->errors[0]);
    }

    #[Test]
    public function validate_cart_with_negative_variant_modifier(): void
    {
        $product = $this->buildProduct(priceAmount: 5000);
        $variant = $this->buildVariant(priceModifier: -1000, stockQuantity: 10);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findById')->willReturn($product);

        $variantRepo = $this->createStub(ProductVariantRepositoryInterface::class);
        $variantRepo->method('findById')->willReturn($variant);

        $service = $this->buildCheckoutService($productRepo, $variantRepo);

        $result = $service->validateCart([
            [
                'productId' => self::PRODUCT_ID,
                'variantId' => self::VARIANT_ID,
                'quantity' => 1,
                'unitPrice' => 4000,
            ],
        ]);

        self::assertTrue($result->isValid);
        self::assertSame(4000, $result->validatedItems[0]['unitPrice']);
    }

    private function buildProduct(int $priceAmount, int $stockQuantity = 100): Product
    {
        $now = new DateTimeImmutable();

        return new Product(
            id: self::PRODUCT_ID,
            tenantId: null,
            sku: 'TST-001',
            status: ProductStatus::Active,
            priceAmount: $priceAmount,
            priceCurrency: 'EUR',
            taxCategory: 'standard',
            stockQuantity: $stockQuantity,
            digital: false,
            contentId: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function buildVariant(int $priceModifier, int $stockQuantity): ProductVariant
    {
        return new ProductVariant(
            id: self::VARIANT_ID,
            productId: self::PRODUCT_ID,
            skuSuffix: 'LG-RED',
            attributeValues: ['size' => 'L', 'color' => 'red'],
            priceModifier: $priceModifier,
            stockQuantity: $stockQuantity,
            mediaAssetId: null,
            sortOrder: 0,
            isActive: true,
        );
    }

    /**
     * @return array{line1: string, city: string, postalCode: string, country: string}
     */
    private static function billingAddress(): array
    {
        return [
            'line1' => '1 Test St',
            'city' => 'Berlin',
            'postalCode' => '10115',
            'country' => 'DE',
        ];
    }

    /**
     * @param ProductRepositoryInterface&Stub $productRepo
     * @param ProductVariantRepositoryInterface&(Stub|\PHPUnit\Framework\MockObject\MockObject) $variantRepo
     */
    private function buildCheckoutService(
        ProductRepositoryInterface $productRepo,
        ProductVariantRepositoryInterface $variantRepo,
    ): CheckoutService {
        $orderRepo = $this->createStub(OrderRepositoryInterface::class);
        $orderItemRepo = $this->createStub(OrderItemRepositoryInterface::class);
        $promotions = $this->createStub(PromotionServiceInterface::class);
        $taxCalculator = $this->createStub(TaxCalculatorInterface::class);

        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $invoiceService->method('generate')->willReturnCallback(
            static function (string $orderId): Invoice {
                $now = new DateTimeImmutable();

                return new Invoice(
                    id: 'inv-' . $orderId,
                    orderId: $orderId,
                    invoiceNumber: 'INV-000001',
                    issuedAt: $now,
                    dueAt: $now->modify('+30 days'),
                    pdfStoragePath: null,
                    pdfHash: null,
                    evidenceHash: null,
                    dataClassification: DataClassification::Pii,
                );
            },
        );

        $digitalDelivery = $this->createStub(DigitalDeliveryServiceInterface::class);
        $digitalDelivery->method('createDownloadTokens')->willReturn([]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($db): mixed {
                return $callback($db);
            },
        );
        $db->method('execute')->willReturn(1);
        $db->method('query')->willReturn(
            new Result([new Row(['last_number' => 1])]),
        );

        $events = $this->createStub(EventDispatcherInterface::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $config = new CommerceConfig(currency: 'EUR');

        return new CheckoutService(
            products: $productRepo,
            orders: $orderRepo,
            orderItems: $orderItemRepo,
            promotions: $promotions,
            taxCalculator: $taxCalculator,
            invoiceService: $invoiceService,
            digitalDelivery: $digitalDelivery,
            db: $db,
            config: $config,
            events: $events,
            variants: $variantRepo,
            auditLogger: $auditLogger,
        );
    }
}
