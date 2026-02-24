<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Comments\Event\CommentModerated;
use Pulsar\Extension\Cms\Comments\Event\CommentSubmitted;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Internal\Commerce\OrderCancelledEvent;
use Pulsar\Extension\Cms\Internal\Commerce\OrderCreatedEvent;
use Pulsar\Extension\Cms\Internal\Commerce\OrderStatusChangedEvent;
use Pulsar\Extension\Cms\Internal\Commerce\PaymentReceivedEvent;
use Pulsar\Extension\Cms\Internal\Commerce\RefundProcessedEvent;

#[CoversClass(CommentModerated::class)]
#[CoversClass(CommentSubmitted::class)]
#[CoversClass(OrderCancelledEvent::class)]
#[CoversClass(OrderCreatedEvent::class)]
#[CoversClass(OrderStatusChangedEvent::class)]
#[CoversClass(PaymentReceivedEvent::class)]
#[CoversClass(RefundProcessedEvent::class)]
final class CmsEventsTest extends TestCase
{
    // --- CommentModerated ---

    #[Test]
    public function commentModeratedConstruction(): void
    {
        $event = new CommentModerated(
            commentId: 'c-1',
            contentId: 'p-1',
            moderatorId: 'admin-1',
            fromStatus: 'pending',
            toStatus: 'approved',
            reason: 'Content is appropriate',
        );

        self::assertSame('c-1', $event->commentId);
        self::assertSame('p-1', $event->contentId);
        self::assertSame('admin-1', $event->moderatorId);
        self::assertSame('pending', $event->fromStatus);
        self::assertSame('approved', $event->toStatus);
        self::assertSame('Content is appropriate', $event->reason);
    }

    // --- CommentSubmitted ---

    #[Test]
    public function commentSubmittedWithAuthor(): void
    {
        $event = new CommentSubmitted(
            commentId: 'c-2',
            contentId: 'p-2',
            authorId: 'user-1',
        );

        self::assertSame('c-2', $event->commentId);
        self::assertSame('p-2', $event->contentId);
        self::assertSame('user-1', $event->authorId);
    }

    #[Test]
    public function commentSubmittedAnonymous(): void
    {
        $event = new CommentSubmitted(
            commentId: 'c-3',
            contentId: 'p-3',
            authorId: null,
        );

        self::assertNull($event->authorId);
    }

    // --- OrderCreatedEvent ---

    #[Test]
    public function orderCreatedEventHoldsOrder(): void
    {
        $order = $this->buildMinimalOrder();
        $event = new OrderCreatedEvent(order: $order);

        self::assertSame($order, $event->order);
        self::assertSame('order-1', $event->order->id);
    }

    // --- OrderCancelledEvent ---

    #[Test]
    public function orderCancelledEventHoldsOrder(): void
    {
        $order = $this->buildMinimalOrder();
        $event = new OrderCancelledEvent(order: $order);

        self::assertSame($order, $event->order);
    }

    // --- OrderStatusChangedEvent ---

    #[Test]
    public function orderStatusChangedEventHoldsStatusAndOrder(): void
    {
        $order = $this->buildMinimalOrder();
        $event = new OrderStatusChangedEvent(
            order: $order,
            newStatus: OrderStatus::Confirmed,
        );

        self::assertSame(OrderStatus::Confirmed, $event->newStatus);
        self::assertSame($order, $event->order);
    }

    // --- PaymentReceivedEvent ---

    #[Test]
    public function paymentReceivedWithPaymentIntent(): void
    {
        $order = $this->buildMinimalOrder();
        $event = new PaymentReceivedEvent(
            order: $order,
            paymentIntentId: 'pi_abc123',
        );

        self::assertSame('pi_abc123', $event->paymentIntentId);
    }

    #[Test]
    public function paymentReceivedWithoutPaymentIntent(): void
    {
        $order = $this->buildMinimalOrder();
        $event = new PaymentReceivedEvent(
            order: $order,
            paymentIntentId: null,
        );

        self::assertNull($event->paymentIntentId);
    }

    // --- RefundProcessedEvent ---

    #[Test]
    public function refundProcessedEventConstruction(): void
    {
        $order = $this->buildMinimalOrder();
        $event = new RefundProcessedEvent(
            order: $order,
            refundAmount: 2500,
            reason: 'Customer request',
        );

        self::assertSame(2500, $event->refundAmount);
        self::assertSame('Customer request', $event->reason);
    }

    private function buildMinimalOrder(): Order
    {
        return new Order(
            id: 'order-1',
            tenantId: null,
            orderNumber: 'ORD-0001',
            customerId: 'cust-1',
            customerEmail: 'test@example.com',
            status: OrderStatus::PendingPayment,
            subtotal: 5000,
            taxAmount: 500,
            discountAmount: 0,
            shippingAmount: 1000,
            shippingMethod: ShippingMethod::Standard,
            total: 6500,
            amountRefunded: 0,
            currency: 'usd',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Pending,
            billingAddress: ['line1' => '123 Main St', 'city' => 'Springfield', 'postalCode' => '62701', 'country' => 'US'],
            shippingAddress: null,
            notes: '',
            dataClassification: DataClassification::Pii,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
