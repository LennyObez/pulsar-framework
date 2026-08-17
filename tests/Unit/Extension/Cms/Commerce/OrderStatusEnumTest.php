<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;

#[CoversNothing]
final class OrderStatusEnumTest extends TestCase
{
    // ── OrderStatus::isTerminal ─────────────────────────────────────

    #[Test]
    public function refundedIsTerminal(): void
    {
        self::assertTrue(OrderStatus::Refunded->isTerminal());
    }

    #[Test]
    public function cancelledIsTerminal(): void
    {
        self::assertTrue(OrderStatus::Cancelled->isTerminal());
    }

    #[Test]
    public function cartIsNotTerminal(): void
    {
        self::assertFalse(OrderStatus::Cart->isTerminal());
    }

    #[Test]
    public function pendingPaymentIsNotTerminal(): void
    {
        self::assertFalse(OrderStatus::PendingPayment->isTerminal());
    }

    #[Test]
    public function confirmedIsNotTerminal(): void
    {
        self::assertFalse(OrderStatus::Confirmed->isTerminal());
    }

    #[Test]
    public function fulfilledIsNotTerminal(): void
    {
        self::assertFalse(OrderStatus::Fulfilled->isTerminal());
    }

    #[Test]
    public function failedIsNotTerminal(): void
    {
        self::assertFalse(OrderStatus::Failed->isTerminal());
    }

    // ── OrderStatus::label ──────────────────────────────────────────

    #[Test]
    public function orderStatusLabels(): void
    {
        self::assertSame('Cart', OrderStatus::Cart->label());
        self::assertSame('Pending Payment', OrderStatus::PendingPayment->label());
        self::assertSame('Confirmed', OrderStatus::Confirmed->label());
        self::assertSame('Fulfilled', OrderStatus::Fulfilled->label());
        self::assertSame('Refunded', OrderStatus::Refunded->label());
        self::assertSame('Failed', OrderStatus::Failed->label());
        self::assertSame('Cancelled', OrderStatus::Cancelled->label());
    }

    // ── PaymentStatus::isPaid ───────────────────────────────────────

    #[Test]
    public function paidIsPaid(): void
    {
        self::assertTrue(PaymentStatus::Paid->isPaid());
    }

    #[Test]
    public function pendingIsNotPaid(): void
    {
        self::assertFalse(PaymentStatus::Pending->isPaid());
    }

    #[Test]
    public function failedIsNotPaid(): void
    {
        self::assertFalse(PaymentStatus::Failed->isPaid());
    }

    #[Test]
    public function refundedPaymentIsNotPaid(): void
    {
        self::assertFalse(PaymentStatus::Refunded->isPaid());
    }

    #[Test]
    public function partiallyRefundedIsNotPaid(): void
    {
        self::assertFalse(PaymentStatus::PartiallyRefunded->isPaid());
    }

    // ── PaymentStatus::label ────────────────────────────────────────

    #[Test]
    public function paymentStatusLabels(): void
    {
        self::assertSame('Pending', PaymentStatus::Pending->label());
        self::assertSame('Paid', PaymentStatus::Paid->label());
        self::assertSame('Failed', PaymentStatus::Failed->label());
        self::assertSame('Refunded', PaymentStatus::Refunded->label());
        self::assertSame('Partially Refunded', PaymentStatus::PartiallyRefunded->label());
    }
}
