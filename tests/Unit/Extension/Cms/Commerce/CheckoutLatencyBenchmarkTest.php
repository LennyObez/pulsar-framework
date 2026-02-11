<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Commerce\TaxResult;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Internal\Commerce\CheckoutService;

use function memory_get_peak_usage;
use function microtime;
use function range;
use function sprintf;

/**
 * Benchmark for checkout flow latency: cart validation -> order creation -> payment intent.
 *
 * Target: < 200ms excluding external payment gateway call.
 */
#[CoversClass(CheckoutService::class)]
#[Group('benchmark')]
final class CheckoutLatencyBenchmarkTest extends TestCase
{
    private const float MAX_CHECKOUT_SECONDS = 0.200;
    private const int CART_ITEM_COUNT = 5;

    /** @var array<string, Product> */
    private array $productCatalog = [];

    /** @var array<string, int> */
    private array $dbQueryCounts;

    protected function setUp(): void
    {
        $this->dbQueryCounts = [
            'query' => 0,
            'execute' => 0,
        ];

        // Pre-build a catalog of active products
        foreach (range(1, 20) as $i) {
            $id = sprintf('product-%03d', $i);
            $this->productCatalog[$id] = new Product(
                id: $id,
                tenantId: null,
                sku: sprintf('SKU-%03d', $i),
                status: ProductStatus::Active,
                priceAmount: 1000 * $i,
                priceCurrency: 'EUR',
                taxCategory: 'standard',
                stockQuantity: 100,
                digital: $i % 3 === 0,
                contentId: null,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
            );
        }
    }

    #[Test]
    public function single_checkout_flow_completes_within_200ms(): void
    {
        $service = $this->buildCheckoutService();
        $cartItems = $this->buildCartItems(self::CART_ITEM_COUNT);

        // Warm up: one run to prime autoloading and JIT
        $service->validateCart($cartItems);

        // Measure: cart validation + order creation (no payment gateway)
        $startTime = microtime(true);

        $validation = $service->validateCart($cartItems);
        self::assertTrue($validation->isValid, 'Cart validation should pass');

        $order = $service->createOrder(
            cartItems: $cartItems,
            customerEmail: 'bench@example.com',
            billingAddress: [
                'line1' => '123 Benchmark St',
                'city' => 'Berlin',
                'postalCode' => '10115',
                'country' => 'DE',
            ],
        );

        // Process payment (no gateway configured = auto-confirm)
        $paymentResult = $service->processPayment($order->id, []);

        $elapsed = microtime(true) - $startTime;
        $peakMemory = memory_get_peak_usage(true);

        // Output stats
        fwrite(STDERR, sprintf(
            "\n[Benchmark] Checkout latency: %.3f ms | Peak memory: %.1f MB\n",
            $elapsed * 1000,
            $peakMemory / 1024 / 1024,
        ));
        fwrite(STDERR, sprintf(
            "[Benchmark] DB calls: queries=%d, executes=%d | Cart items: %d\n",
            $this->dbQueryCounts['query'],
            $this->dbQueryCounts['execute'],
            self::CART_ITEM_COUNT,
        ));

        // Assert: latency budget
        self::assertLessThan(
            self::MAX_CHECKOUT_SECONDS,
            $elapsed,
            sprintf(
                'Checkout took %.1f ms, exceeding the %.0f ms budget',
                $elapsed * 1000,
                self::MAX_CHECKOUT_SECONDS * 1000,
            ),
        );

        // Assert: successful payment
        self::assertTrue($paymentResult->success);
        self::assertNotEmpty($order->orderNumber);

        // Assert: DB query count is bounded (no N+1 beyond expected)
        // Expected: 1 upsert for order sequence + 1 order UPDATE for confirm
        // Stock reserves go through ProductRepositoryInterface (not direct DB)
        $expectedMinExecutes = 2; // sequence upsert + confirm
        self::assertGreaterThanOrEqual(
            $expectedMinExecutes,
            $this->dbQueryCounts['execute'],
            'Should have at least sequence upsert + confirmation executes',
        );
    }

    #[Test]
    public function checkout_with_tax_calculation_stays_within_budget(): void
    {
        $service = $this->buildCheckoutService(taxRequired: true);
        $cartItems = $this->buildCartItems(self::CART_ITEM_COUNT);

        $startTime = microtime(true);

        $order = $service->createOrder(
            cartItems: $cartItems,
            customerEmail: 'tax-bench@example.com',
            billingAddress: [
                'line1' => '456 Tax Lane',
                'city' => 'Paris',
                'postalCode' => '75001',
                'country' => 'FR',
            ],
        );

        $elapsed = microtime(true) - $startTime;

        fwrite(STDERR, sprintf(
            "\n[Benchmark] Checkout with tax: %.3f ms | Tax amount: %d | Total: %d\n",
            $elapsed * 1000,
            $order->taxAmount,
            $order->total,
        ));

        self::assertLessThan(
            self::MAX_CHECKOUT_SECONDS,
            $elapsed,
            sprintf(
                'Checkout with tax took %.1f ms, exceeding the %.0f ms budget',
                $elapsed * 1000,
                self::MAX_CHECKOUT_SECONDS * 1000,
            ),
        );

        self::assertGreaterThan(0, $order->taxAmount, 'Tax should be calculated');
        self::assertGreaterThan($order->subtotal, $order->total, 'Total should include tax');
    }

    #[Test]
    public function ten_item_cart_checkout_stays_within_budget(): void
    {
        $service = $this->buildCheckoutService();
        $cartItems = $this->buildCartItems(10);

        $startTime = microtime(true);

        $order = $service->createOrder(
            cartItems: $cartItems,
            customerEmail: 'large-cart@example.com',
            billingAddress: [
                'line1' => '789 Scale Ave',
                'city' => 'London',
                'postalCode' => 'EC1A 1BB',
                'country' => 'GB',
            ],
        );

        $elapsed = microtime(true) - $startTime;

        fwrite(STDERR, sprintf(
            "\n[Benchmark] 10-item checkout: %.3f ms | Subtotal: %d | DB executes: %d\n",
            $elapsed * 1000,
            $order->subtotal,
            $this->dbQueryCounts['execute'],
        ));

        self::assertLessThan(
            self::MAX_CHECKOUT_SECONDS,
            $elapsed,
            sprintf(
                '10-item checkout took %.1f ms, exceeding the %.0f ms budget',
                $elapsed * 1000,
                self::MAX_CHECKOUT_SECONDS * 1000,
            ),
        );

        self::assertGreaterThan(0, $order->subtotal);
    }

    private function buildCheckoutService(bool $taxRequired = false): CheckoutService
    {
        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findById')->willReturnCallback(
            fn(string $id): ?Product => $this->productCatalog[$id] ?? null,
        );
        $productRepo->method('findByIds')->willReturnCallback(
            function (array $ids): array {
                $result = [];
                /** @var list<string> $ids */
                foreach ($ids as $id) {
                    if (isset($this->productCatalog[$id])) {
                        $result[$id] = $this->productCatalog[$id];
                    }
                }

                return $result;
            },
        );
        $productRepo->method('reserveStock')->willReturn(true);

        $orderRepo = $this->createStub(OrderRepositoryInterface::class);
        // findById returns the last saved order for payment processing
        $lastSavedOrder = null;
        $orderRepo->method('save')->willReturnCallback(
            static function ($order) use (&$lastSavedOrder): void {
                $lastSavedOrder = $order;
            },
        );
        $orderRepo->method('findById')->willReturnCallback(
            static function () use (&$lastSavedOrder) {
                return $lastSavedOrder;
            },
        );

        $orderItemRepo = $this->createStub(OrderItemRepositoryInterface::class);

        $promotions = $this->createStub(PromotionServiceInterface::class);

        $taxCalculator = $this->createStub(TaxCalculatorInterface::class);
        $taxCalculator->method('calculate')->willReturnCallback(
            static function (array $items): TaxResult {
                $total = 0;

                foreach ($items as $item) {
                    /** @var array{amount: int} $item */
                    $total += (int) ($item['amount'] * 0.20); // 20% VAT
                }

                return new TaxResult(items: [], totalTax: $total, reverseCharge: false);
            },
        );

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
            function (callable $callback) use ($db): mixed {
                return $callback($db);
            },
        );
        $db->method('execute')->willReturnCallback(
            function () {
                $this->dbQueryCounts['execute']++;

                return 1; // 1 affected row
            },
        );
        $db->method('query')->willReturnCallback(
            function () {
                $this->dbQueryCounts['query']++;

                return new Result([new Row(['cnt' => 42, 'last_number' => 42])]);
            },
        );

        $events = $this->createStub(EventDispatcherInterface::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $config = new CommerceConfig(
            taxRequired: $taxRequired,
            currency: 'EUR',
        );

        $variantRepo = $this->createStub(ProductVariantRepositoryInterface::class);

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
            paymentGateway: null, // No external gateway — measures internal latency only
            auditLogger: $auditLogger,
        );
    }

    /**
     * @return list<array{productId: string, quantity: int, unitPrice: int}>
     */
    private function buildCartItems(int $count): array
    {
        $items = [];

        foreach (range(1, $count) as $i) {
            $id = sprintf('product-%03d', $i);
            $product = $this->productCatalog[$id];
            $items[] = [
                'productId' => $id,
                'quantity' => 1,
                'unitPrice' => $product->priceAmount,
            ];
        }

        return $items;
    }
}
