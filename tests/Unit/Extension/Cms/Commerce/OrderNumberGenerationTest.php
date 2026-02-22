<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Internal\Commerce\CheckoutService;

use function str_contains;

#[CoversClass(CheckoutService::class)]
final class OrderNumberGenerationTest extends TestCase
{
    #[Test]
    public function order_number_uses_atomic_sequence(): void
    {
        $executedSql = [];
        $queriedSql = [];

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($db): mixed {
                return $callback($db);
            },
        );
        $db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 1;
            },
        );
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$queriedSql): Result {
                $queriedSql[] = $sql;

                if (str_contains($sql, 'last_number')) {
                    return new Result([new Row(['last_number' => 1])]);
                }

                return new Result([new Row(['cnt' => 0])]);
            },
        );

        $service = $this->buildService($db);

        $order = $service->createOrder(
            cartItems: [['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 5000]],
            customerEmail: 'test@example.com',
            billingAddress: ['line1' => '1 Test St', 'city' => 'Berlin', 'postalCode' => '10115', 'country' => 'DE'],
        );

        self::assertSame('ORD-000001', $order->orderNumber);

        // Verify the upsert SQL was executed (INSERT ... ON CONFLICT)
        $upsertExecuted = false;

        foreach ($executedSql as $sql) {
            if (str_contains($sql, 'cms_order_sequences') && str_contains($sql, 'ON CONFLICT')) {
                $upsertExecuted = true;

                break;
            }
        }

        self::assertTrue($upsertExecuted, 'Expected atomic upsert into cms_order_sequences');

        // Verify the sequence SELECT was issued
        $sequenceQueried = false;

        foreach ($queriedSql as $sql) {
            if (str_contains($sql, 'last_number') && str_contains($sql, 'cms_order_sequences')) {
                $sequenceQueried = true;

                break;
            }
        }

        self::assertTrue($sequenceQueried, 'Expected SELECT last_number FROM cms_order_sequences');
    }

    #[Test]
    public function order_number_uses_global_tenant_when_null(): void
    {
        $capturedBindings = [];

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($db): mixed {
                return $callback($db);
            },
        );
        $db->method('execute')->willReturnCallback(
            static function (string $sql, array $bindings = []) use (&$capturedBindings): int {
                if (str_contains($sql, 'cms_order_sequences')) {
                    $capturedBindings[] = $bindings;
                }

                return 1;
            },
        );
        $db->method('query')->willReturnCallback(
            static function (string $sql): Result {
                if (str_contains($sql, 'last_number')) {
                    return new Result([new Row(['last_number' => 5])]);
                }

                return new Result([new Row(['cnt' => 0])]);
            },
        );

        $service = $this->buildService($db);

        $order = $service->createOrder(
            cartItems: [['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 5000]],
            customerEmail: 'test@example.com',
            billingAddress: ['line1' => '1 Test St', 'city' => 'Berlin', 'postalCode' => '10115', 'country' => 'DE'],
            tenantId: null,
        );

        self::assertSame('ORD-000005', $order->orderNumber);

        // When tenantId is null, the sequence should use '__global__'
        self::assertNotEmpty($capturedBindings, 'Expected at least one upsert binding capture');
        self::assertSame('__global__', $capturedBindings[0]['tenant_id']);
    }

    #[Test]
    public function order_number_uses_tenant_id_when_provided(): void
    {
        $capturedBindings = [];

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($db): mixed {
                return $callback($db);
            },
        );
        $db->method('execute')->willReturnCallback(
            static function (string $sql, array $bindings = []) use (&$capturedBindings): int {
                if (str_contains($sql, 'cms_order_sequences')) {
                    $capturedBindings[] = $bindings;
                }

                return 1;
            },
        );
        $db->method('query')->willReturnCallback(
            static function (string $sql): Result {
                if (str_contains($sql, 'last_number')) {
                    return new Result([new Row(['last_number' => 12])]);
                }

                return new Result([new Row(['cnt' => 0])]);
            },
        );

        $service = $this->buildService($db);

        $order = $service->createOrder(
            cartItems: [['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 5000]],
            customerEmail: 'test@example.com',
            billingAddress: ['line1' => '1 Test St', 'city' => 'Berlin', 'postalCode' => '10115', 'country' => 'DE'],
            tenantId: 'tenant-abc',
        );

        self::assertSame('ORD-000012', $order->orderNumber);
        self::assertSame('tenant-abc', $capturedBindings[0]['tenant_id']);
    }

    #[Test]
    public function sequential_orders_get_incrementing_numbers(): void
    {
        $sequenceCounter = 0;

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($db): mixed {
                return $callback($db);
            },
        );
        $db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$sequenceCounter): int {
                if (str_contains($sql, 'cms_order_sequences') && str_contains($sql, 'ON CONFLICT')) {
                    $sequenceCounter++;
                }

                return 1;
            },
        );
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$sequenceCounter): Result {
                if (str_contains($sql, 'last_number')) {
                    return new Result([new Row(['last_number' => $sequenceCounter])]);
                }

                return new Result([new Row(['cnt' => 0])]);
            },
        );

        $service = $this->buildService($db);

        $cartItems = [['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 5000]];
        $billing = ['line1' => '1 Test St', 'city' => 'Berlin', 'postalCode' => '10115', 'country' => 'DE'];

        $order1 = $service->createOrder(
            cartItems: $cartItems,
            customerEmail: 'a@example.com',
            billingAddress: $billing,
        );
        $order2 = $service->createOrder(
            cartItems: $cartItems,
            customerEmail: 'b@example.com',
            billingAddress: $billing,
        );
        $order3 = $service->createOrder(
            cartItems: $cartItems,
            customerEmail: 'c@example.com',
            billingAddress: $billing,
        );

        self::assertSame('ORD-000001', $order1->orderNumber);
        self::assertSame('ORD-000002', $order2->orderNumber);
        self::assertSame('ORD-000003', $order3->orderNumber);
    }

    #[Test]
    public function no_count_query_on_cms_orders_table(): void
    {
        $queriedSql = [];

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($db): mixed {
                return $callback($db);
            },
        );
        $db->method('execute')->willReturn(1);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$queriedSql): Result {
                $queriedSql[] = $sql;

                if (str_contains($sql, 'last_number')) {
                    return new Result([new Row(['last_number' => 1])]);
                }

                return new Result([new Row(['cnt' => 0])]);
            },
        );

        $service = $this->buildService($db);

        $service->createOrder(
            cartItems: [['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 5000]],
            customerEmail: 'test@example.com',
            billingAddress: ['line1' => '1 Test St', 'city' => 'Berlin', 'postalCode' => '10115', 'country' => 'DE'],
        );

        // The old racy pattern was SELECT COUNT(*) FROM cms_orders — verify it's gone
        foreach ($queriedSql as $sql) {
            self::assertFalse(
                str_contains($sql, 'COUNT(*)') && str_contains($sql, 'cms_orders'),
                'Should not use SELECT COUNT(*) FROM cms_orders for order number generation',
            );
        }
    }

    private function buildService(ConnectionInterface $db): CheckoutService
    {
        $product = new Product(
            id: 'p1',
            tenantId: null,
            sku: 'SKU-001',
            status: ProductStatus::Active,
            priceAmount: 5000,
            priceCurrency: 'EUR',
            taxCategory: 'standard',
            stockQuantity: 100,
            digital: false,
            contentId: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findById')->willReturn($product);
        $productRepo->method('findByIds')->willReturn(['p1' => $product]);
        $productRepo->method('reserveStock')->willReturn(true);

        $orderRepo = $this->createStub(OrderRepositoryInterface::class);
        $orderItemRepo = $this->createStub(OrderItemRepositoryInterface::class);
        $promotions = $this->createStub(PromotionServiceInterface::class);
        $taxCalculator = $this->createStub(TaxCalculatorInterface::class);

        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $digitalDelivery = $this->createStub(DigitalDeliveryServiceInterface::class);
        $events = $this->createStub(EventDispatcherInterface::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $config = new CommerceConfig(taxRequired: false, currency: 'EUR');

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
            auditLogger: $auditLogger,
        );
    }
}
