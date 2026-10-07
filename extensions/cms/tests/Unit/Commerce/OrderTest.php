<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Content\DataClassification;

#[CoversClass(Order::class)]
final class OrderTest extends TestCase
{
    private const array BILLING_ADDRESS = [
        'line1' => '123 Main St',
        'city' => 'Springfield',
        'postalCode' => '62701',
        'country' => 'US',
    ];

    #[Test]
    public function create_returns_cart_order_with_zero_totals(): void
    {
        $order = Order::create(
            id: 'order-1',
            orderNumber: 'ORD-001',
            customerId: 'cust-1',
            customerEmail: 'test@example.com',
            currency: 'USD',
            billingAddress: self::BILLING_ADDRESS,
        );

        self::assertSame('order-1', $order->id);
        self::assertSame('ORD-001', $order->orderNumber);
        self::assertSame('cust-1', $order->customerId);
        self::assertSame('test@example.com', $order->customerEmail);
        self::assertSame(OrderStatus::Cart, $order->status);
        self::assertSame(0, $order->subtotal);
        self::assertSame(0, $order->taxAmount);
        self::assertSame(0, $order->discountAmount);
        self::assertSame(0, $order->shippingAmount);
        self::assertNull($order->shippingMethod);
        self::assertSame(0, $order->total);
        self::assertSame(0, $order->amountRefunded);
        self::assertSame('USD', $order->currency);
        self::assertNull($order->paymentIntentId);
        self::assertSame(PaymentStatus::Pending, $order->paymentStatus);
        self::assertSame(self::BILLING_ADDRESS, $order->billingAddress);
        self::assertNull($order->shippingAddress);
        self::assertNull($order->notes);
        self::assertSame(DataClassification::Pii, $order->dataClassification);
    }

    #[Test]
    public function create_with_optional_tenant_and_classification(): void
    {
        $order = Order::create(
            id: 'order-2',
            orderNumber: 'ORD-002',
            customerId: 'cust-1',
            customerEmail: 'test@example.com',
            currency: 'EUR',
            billingAddress: self::BILLING_ADDRESS,
            tenantId: 'tenant-1',
            dataClassification: DataClassification::Confidential,
        );

        self::assertSame('tenant-1', $order->tenantId);
        self::assertSame(DataClassification::Confidential, $order->dataClassification);
    }

    #[Test]
    public function isPaid_delegates_to_payment_status(): void
    {
        $now = new DateTimeImmutable();
        $paidOrder = new Order(
            id: 'o1',
            tenantId: null,
            orderNumber: 'ORD-1',
            customerId: 'c1',
            customerEmail: 'a@b.com',
            status: OrderStatus::Confirmed,
            subtotal: 1000,
            taxAmount: 100,
            discountAmount: 0,
            shippingAmount: 500,
            shippingMethod: ShippingMethod::Standard,
            total: 1600,
            amountRefunded: 0,
            currency: 'USD',
            paymentIntentId: 'pi_123',
            paymentStatus: PaymentStatus::Paid,
            billingAddress: self::BILLING_ADDRESS,
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertTrue($paidOrder->isPaid());
    }

    #[Test]
    public function isPaid_returns_false_for_pending_payment(): void
    {
        $order = Order::create('o1', 'ORD-1', 'c1', 'a@b.com', 'USD', self::BILLING_ADDRESS);

        self::assertFalse($order->isPaid());
    }

    #[Test]
    public function isFulfilled_returns_true_only_for_fulfilled_status(): void
    {
        $now = new DateTimeImmutable();
        $fulfilledOrder = new Order(
            id: 'o1',
            tenantId: null,
            orderNumber: 'ORD-1',
            customerId: 'c1',
            customerEmail: 'a@b.com',
            status: OrderStatus::Fulfilled,
            subtotal: 0,
            taxAmount: 0,
            discountAmount: 0,
            shippingAmount: 0,
            shippingMethod: null,
            total: 0,
            amountRefunded: 0,
            currency: 'USD',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Paid,
            billingAddress: self::BILLING_ADDRESS,
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertTrue($fulfilledOrder->isFulfilled());

        $cartOrder = Order::create('o2', 'ORD-2', 'c1', 'a@b.com', 'USD', self::BILLING_ADDRESS);
        self::assertFalse($cartOrder->isFulfilled());
    }

    #[Test]
    public function isCancelled_returns_true_only_for_cancelled_status(): void
    {
        $now = new DateTimeImmutable();
        $cancelledOrder = new Order(
            id: 'o1',
            tenantId: null,
            orderNumber: 'ORD-1',
            customerId: 'c1',
            customerEmail: 'a@b.com',
            status: OrderStatus::Cancelled,
            subtotal: 0,
            taxAmount: 0,
            discountAmount: 0,
            shippingAmount: 0,
            shippingMethod: null,
            total: 0,
            amountRefunded: 0,
            currency: 'USD',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Pending,
            billingAddress: self::BILLING_ADDRESS,
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertTrue($cancelledOrder->isCancelled());

        $cartOrder = Order::create('o2', 'ORD-2', 'c1', 'a@b.com', 'USD', self::BILLING_ADDRESS);
        self::assertFalse($cartOrder->isCancelled());
    }
}
