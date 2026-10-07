<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
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
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Internal\Commerce\CheckoutService;

use function count;
use function sprintf;

/**
 * Verifies that createOrder() batch-loads products via findByIds()
 * instead of issuing N individual findById() calls.
 */
#[CoversClass(CheckoutService::class)]
final class CheckoutBatchLoadTest extends TestCase
{
    #[Test]
    public function create_order_calls_find_by_ids_once_and_never_find_by_id(): void
    {
        $products = $this->buildProductCatalog(3);
        $cartItems = $this->buildCartItems($products);

        /** @var ProductRepositoryInterface&MockObject $productRepo */
        $productRepo = $this->createMock(ProductRepositoryInterface::class);

        $productRepo->expects(self::once())
            ->method('findByIds')
            ->willReturn($products);

        $productRepo->expects(self::never())
            ->method('findById');

        $productRepo->method('reserveStock')->willReturn(true);

        $service = $this->buildCheckoutService($productRepo);

        $order = $service->createOrder(
            cartItems: $cartItems,
            customerEmail: 'batch-test@example.com',
            billingAddress: [
                'line1' => '1 Test St',
                'city' => 'Berlin',
                'postalCode' => '10115',
                'country' => 'DE',
            ],
        );

        self::assertNotEmpty($order->orderNumber);
        self::assertSame(count($cartItems), count($cartItems));
    }

    #[Test]
    public function create_order_with_ten_items_still_calls_find_by_ids_once(): void
    {
        $products = $this->buildProductCatalog(10);
        $cartItems = $this->buildCartItems($products);

        /** @var ProductRepositoryInterface&MockObject $productRepo */
        $productRepo = $this->createMock(ProductRepositoryInterface::class);

        $productRepo->expects(self::once())
            ->method('findByIds')
            ->willReturn($products);

        $productRepo->expects(self::never())
            ->method('findById');

        $productRepo->method('reserveStock')->willReturn(true);

        $service = $this->buildCheckoutService($productRepo);

        $order = $service->createOrder(
            cartItems: $cartItems,
            customerEmail: 'ten-items@example.com',
            billingAddress: [
                'line1' => '10 Batch Lane',
                'city' => 'Munich',
                'postalCode' => '80331',
                'country' => 'DE',
            ],
        );

        self::assertNotEmpty($order->orderNumber);
        self::assertGreaterThan(0, $order->subtotal);
    }

    #[Test]
    public function validate_cart_public_method_still_uses_find_by_id(): void
    {
        $products = $this->buildProductCatalog(2);
        $cartItems = $this->buildCartItems($products);

        /** @var ProductRepositoryInterface&MockObject $productRepo */
        $productRepo = $this->createMock(ProductRepositoryInterface::class);

        $productRepo->expects(self::exactly(2))
            ->method('findById')
            ->willReturnCallback(
                static fn(string $id): ?Product => $products[$id] ?? null,
            );

        $productRepo->expects(self::never())
            ->method('findByIds');

        $service = $this->buildCheckoutService($productRepo);

        $result = $service->validateCart($cartItems);

        self::assertTrue($result->isValid);
        self::assertCount(2, $result->validatedItems);
    }

    /**
     * @return array<string, Product>
     */
    private function buildProductCatalog(int $count): array
    {
        $products = [];
        $now = new DateTimeImmutable();

        for ($i = 1; $i <= $count; $i++) {
            $id = sprintf('prod-%03d', $i);
            $products[$id] = new Product(
                id: $id,
                tenantId: null,
                sku: sprintf('SKU-%03d', $i),
                status: ProductStatus::Active,
                priceAmount: 1000 * $i,
                priceCurrency: 'EUR',
                taxCategory: 'standard',
                stockQuantity: 100,
                digital: false,
                contentId: null,
                createdAt: $now,
                updatedAt: $now,
            );
        }

        return $products;
    }

    /**
     * @param array<string, Product> $products
     * @return list<array{productId: string, quantity: int, unitPrice: int}>
     */
    private function buildCartItems(array $products): array
    {
        $items = [];

        foreach ($products as $product) {
            $items[] = [
                'productId' => $product->id,
                'quantity' => 1,
                'unitPrice' => $product->priceAmount,
            ];
        }

        return $items;
    }

    private function buildCheckoutService(ProductRepositoryInterface $productRepo): CheckoutService
    {
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
