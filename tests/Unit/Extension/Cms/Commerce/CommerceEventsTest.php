<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Event\DigitalDownloadReady;
use Pulsar\Extension\Cms\Commerce\Event\InvoiceGenerated;
use Pulsar\Extension\Cms\Commerce\Event\OrderCreated;
use Pulsar\Extension\Cms\Commerce\Event\OrderStatusChanged;
use Pulsar\Extension\Cms\Commerce\Event\PaymentReceived;
use Pulsar\Extension\Cms\Commerce\Event\RefundProcessed;
use Pulsar\Extension\Cms\Commerce\OrderStatus;

#[CoversClass(DigitalDownloadReady::class)]
#[CoversClass(InvoiceGenerated::class)]
#[CoversClass(OrderCreated::class)]
#[CoversClass(OrderStatusChanged::class)]
#[CoversClass(PaymentReceived::class)]
#[CoversClass(RefundProcessed::class)]
final class CommerceEventsTest extends TestCase
{
    #[Test]
    public function digitalDownloadReadyConstructor(): void
    {
        $event = new DigitalDownloadReady(
            orderId: 'order-01',
            downloadLinks: [
                ['token' => 'tok_abc123', 'fileName' => 'ebook.pdf'],
                ['token' => 'tok_def456', 'fileName' => 'bonus-chapter.pdf'],
            ],
        );

        self::assertSame('order-01', $event->orderId);
        self::assertCount(2, $event->downloadLinks);
        self::assertSame('tok_abc123', $event->downloadLinks[0]['token']);
        self::assertSame('ebook.pdf', $event->downloadLinks[0]['fileName']);
    }

    #[Test]
    public function invoiceGeneratedConstructor(): void
    {
        $event = new InvoiceGenerated(
            invoiceId: 'inv-01',
            orderId: 'order-01',
            invoiceNumber: 'INV-2025-000042',
        );

        self::assertSame('inv-01', $event->invoiceId);
        self::assertSame('order-01', $event->orderId);
        self::assertSame('INV-2025-000042', $event->invoiceNumber);
    }

    #[Test]
    public function orderCreatedConstructor(): void
    {
        $event = new OrderCreated(
            orderId: 'order-01',
            orderNumber: 'ORD-000123',
            customerId: 'cust-01',
        );

        self::assertSame('order-01', $event->orderId);
        self::assertSame('ORD-000123', $event->orderNumber);
        self::assertSame('cust-01', $event->customerId);
    }

    #[Test]
    public function orderStatusChangedConstructor(): void
    {
        $event = new OrderStatusChanged(
            orderId: 'order-01',
            previousStatus: OrderStatus::PendingPayment,
            newStatus: OrderStatus::Confirmed,
            reason: 'Payment confirmed via webhook',
        );

        self::assertSame('order-01', $event->orderId);
        self::assertSame(OrderStatus::PendingPayment, $event->previousStatus);
        self::assertSame(OrderStatus::Confirmed, $event->newStatus);
        self::assertSame('Payment confirmed via webhook', $event->reason);
    }

    #[Test]
    public function orderStatusChangedWithNoReason(): void
    {
        $event = new OrderStatusChanged(
            orderId: 'order-02',
            previousStatus: OrderStatus::Confirmed,
            newStatus: OrderStatus::Fulfilled,
            reason: null,
        );

        self::assertNull($event->reason);
    }

    #[Test]
    public function paymentReceivedConstructor(): void
    {
        $event = new PaymentReceived(
            orderId: 'order-01',
            amount: 49_99,
            paymentIntentId: 'pi_3N4x5y6z',
        );

        self::assertSame('order-01', $event->orderId);
        self::assertSame(49_99, $event->amount);
        self::assertSame('pi_3N4x5y6z', $event->paymentIntentId);
    }

    #[Test]
    public function refundProcessedConstructor(): void
    {
        $event = new RefundProcessed(
            orderId: 'order-01',
            amount: 25_00,
            refundId: 'ref-01',
            reason: 'Customer requested cancellation',
        );

        self::assertSame('order-01', $event->orderId);
        self::assertSame(25_00, $event->amount);
        self::assertSame('ref-01', $event->refundId);
        self::assertSame('Customer requested cancellation', $event->reason);
    }

    #[Test]
    public function refundProcessedWithNoReason(): void
    {
        $event = new RefundProcessed(
            orderId: 'order-02',
            amount: 10_00,
            refundId: 'ref-02',
            reason: null,
        );

        self::assertNull($event->reason);
    }
}
