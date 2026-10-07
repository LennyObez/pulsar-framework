<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Content\DataClassification;

#[CoversClass(Order::class)]
final class OrderTest extends TestCase
{
    #[Test]
    public function createReturnsCartOrderWithDefaults(): void
    {
        $order = Order::create(
            id: 'order-01',
            orderNumber: 'ORD-000001',
            customerId: 'cust-01',
            customerEmail: 'test@example.com',
            currency: 'USD',
            billingAddress: ['line1' => '123 Main St', 'city' => 'Anytown', 'postalCode' => '12345', 'country' => 'US'],
        );

        self::assertSame('order-01', $order->id);
        self::assertNull($order->tenantId);
        self::assertSame('ORD-000001', $order->orderNumber);
        self::assertSame('cust-01', $order->customerId);
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
        self::assertSame('123 Main St', $order->billingAddress['line1']);
        self::assertNull($order->shippingAddress);
        self::assertNull($order->notes);
        self::assertSame(DataClassification::Pii, $order->dataClassification);
        self::assertInstanceOf(DateTimeImmutable::class, $order->createdAt);
        self::assertInstanceOf(DateTimeImmutable::class, $order->updatedAt);
    }

    #[Test]
    public function createWithTenantIdAndClassification(): void
    {
        $order = Order::create(
            id: 'order-02',
            orderNumber: 'ORD-000002',
            customerId: 'cust-02',
            customerEmail: 'tenant@example.com',
            currency: 'EUR',
            billingAddress: ['line1' => '456 Oak Ave', 'city' => 'Berlin', 'postalCode' => '10115', 'country' => 'DE'],
            tenantId: 'tenant-01',
            dataClassification: DataClassification::Confidential,
        );

        self::assertSame('tenant-01', $order->tenantId);
        self::assertSame(DataClassification::Confidential, $order->dataClassification);
        self::assertSame('EUR', $order->currency);
    }

    #[Test]
    public function isPaidReturnsTrueWhenPaymentStatusIsPaid(): void
    {
        $order = $this->createOrderWithPaymentStatus(PaymentStatus::Paid);

        self::assertTrue($order->isPaid());
    }

    #[Test]
    public function isPaidReturnsFalseWhenPaymentStatusIsPending(): void
    {
        $order = $this->createOrderWithPaymentStatus(PaymentStatus::Pending);

        self::assertFalse($order->isPaid());
    }

    #[Test]
    public function isPaidReturnsFalseWhenPaymentStatusIsFailed(): void
    {
        $order = $this->createOrderWithPaymentStatus(PaymentStatus::Failed);

        self::assertFalse($order->isPaid());
    }

    #[Test]
    public function isFulfilledReturnsTrueWhenStatusIsFulfilled(): void
    {
        $order = $this->createOrderWithStatus(OrderStatus::Fulfilled);

        self::assertTrue($order->isFulfilled());
    }

    #[Test]
    public function isFulfilledReturnsFalseWhenStatusIsConfirmed(): void
    {
        $order = $this->createOrderWithStatus(OrderStatus::Confirmed);

        self::assertFalse($order->isFulfilled());
    }

    #[Test]
    public function isCancelledReturnsTrueWhenStatusIsCancelled(): void
    {
        $order = $this->createOrderWithStatus(OrderStatus::Cancelled);

        self::assertTrue($order->isCancelled());
    }

    #[Test]
    public function isCancelledReturnsFalseWhenStatusIsCart(): void
    {
        $order = $this->createOrderWithStatus(OrderStatus::Cart);

        self::assertFalse($order->isCancelled());
    }

    private function createOrderWithPaymentStatus(PaymentStatus $paymentStatus): Order
    {
        $now = new DateTimeImmutable();

        return new Order(
            id: 'order-test',
            tenantId: null,
            orderNumber: 'ORD-TEST',
            customerId: 'cust-test',
            customerEmail: 'test@example.com',
            status: OrderStatus::Confirmed,
            subtotal: 1000,
            taxAmount: 100,
            discountAmount: 0,
            shippingAmount: 0,
            shippingMethod: null,
            total: 1100,
            amountRefunded: 0,
            currency: 'USD',
            paymentIntentId: 'pi_test',
            paymentStatus: $paymentStatus,
            billingAddress: ['line1' => 'x', 'city' => 'y', 'postalCode' => 'z', 'country' => 'US'],
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function createOrderWithStatus(OrderStatus $status): Order
    {
        $now = new DateTimeImmutable();

        return new Order(
            id: 'order-test',
            tenantId: null,
            orderNumber: 'ORD-TEST',
            customerId: 'cust-test',
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
            billingAddress: ['line1' => 'x', 'city' => 'y', 'postalCode' => 'z', 'country' => 'US'],
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
