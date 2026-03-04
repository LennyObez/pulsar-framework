<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\CheckoutServiceInterface;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Internal\Commerce\CheckoutService;
use ReflectionMethod;

/**
 * Verifies that cancelCheckout() passes the actorId to the audit logger.
 */
#[CoversClass(CheckoutService::class)]
final class CheckoutServiceCancelAuditTest extends TestCase
{
    private function makeOrder(string $id, OrderStatus $status): Order
    {
        return new Order(
            id: $id,
            tenantId: null,
            orderNumber: 'ORD-001',
            customerId: 'cust-1',
            customerEmail: 'test@example.com',
            status: $status,
            subtotal: 1000,
            taxAmount: 100,
            discountAmount: 0,
            shippingAmount: 0,
            shippingMethod: null,
            total: 1100,
            amountRefunded: 0,
            currency: 'USD',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Pending,
            billingAddress: ['line1' => '123 Main St', 'city' => 'NY', 'postalCode' => '10001', 'country' => 'US'],
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Internal,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function cancelCheckoutPassesActorIdToAuditLogger(): void
    {
        $order = $this->makeOrder('order-123', OrderStatus::PendingPayment);

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);

        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $orderItems->method('findByOrder')->willReturn([]);

        $events = $this->createStub(EventDispatcherInterface::class);

        /** @var AuditLoggerInterface&MockObject $auditLogger */
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                'admin-user-42',
                self::anything(),
                self::anything(),
                self::anything(),
            );

        $service = new CheckoutService(
            products: $this->createStub(ProductRepositoryInterface::class),
            orders: $orders,
            orderItems: $orderItems,
            promotions: $this->createStub(PromotionServiceInterface::class),
            taxCalculator: $this->createStub(TaxCalculatorInterface::class),
            invoiceService: $this->createStub(InvoiceServiceInterface::class),
            digitalDelivery: $this->createStub(DigitalDeliveryServiceInterface::class),
            db: $this->createStub(ConnectionInterface::class),
            config: new CommerceConfig(),
            events: $events,
            variants: $this->createStub(ProductVariantRepositoryInterface::class),
            auditLogger: $auditLogger,
        );

        $service->cancelCheckout('order-123', 'admin-user-42');
    }

    #[Test]
    public function cancelCheckoutInterfaceAcceptsActorId(): void
    {
        $method = new ReflectionMethod(CheckoutServiceInterface::class, 'cancelCheckout');
        $params = $method->getParameters();

        self::assertCount(2, $params);
        self::assertSame('orderId', $params[0]->getName());
        self::assertSame('actorId', $params[1]->getName());
        self::assertTrue($params[1]->allowsNull());
        self::assertTrue($params[1]->isDefaultValueAvailable());
        self::assertNull($params[1]->getDefaultValue());
    }
}
