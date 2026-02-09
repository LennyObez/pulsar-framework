<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\OrderStatus;

#[CoversClass(OrderStatus::class)]
final class OrderStatusTest extends TestCase
{
    // ── isTerminal ──────────────────────────────────────────────────

    #[Test]
    public function test_refunded_is_terminal(): void
    {
        self::assertTrue(OrderStatus::Refunded->isTerminal());
    }

    #[Test]
    public function test_cancelled_is_terminal(): void
    {
        self::assertTrue(OrderStatus::Cancelled->isTerminal());
    }

    #[Test]
    #[DataProvider('nonTerminalStatusProvider')]
    public function test_non_terminal_statuses(OrderStatus $status): void
    {
        self::assertFalse($status->isTerminal());
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function nonTerminalStatusProvider(): iterable
    {
        yield 'Cart' => [OrderStatus::Cart];
        yield 'PendingPayment' => [OrderStatus::PendingPayment];
        yield 'Confirmed' => [OrderStatus::Confirmed];
        yield 'Fulfilled' => [OrderStatus::Fulfilled];
        yield 'Failed' => [OrderStatus::Failed];
    }

    // ── label ───────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('labelProvider')]
    public function test_label_returns_human_readable_string(OrderStatus $status, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $status->label());
    }

    /**
     * @return iterable<string, array{OrderStatus, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'Cart' => [OrderStatus::Cart, 'Cart'];
        yield 'PendingPayment' => [OrderStatus::PendingPayment, 'Pending Payment'];
        yield 'Confirmed' => [OrderStatus::Confirmed, 'Confirmed'];
        yield 'Fulfilled' => [OrderStatus::Fulfilled, 'Fulfilled'];
        yield 'Refunded' => [OrderStatus::Refunded, 'Refunded'];
        yield 'Failed' => [OrderStatus::Failed, 'Failed'];
        yield 'Cancelled' => [OrderStatus::Cancelled, 'Cancelled'];
    }

    // ── Backed enum values ──────────────────────────────────────────

    #[Test]
    public function test_all_statuses_have_expected_string_values(): void
    {
        self::assertSame('cart', OrderStatus::Cart->value);
        self::assertSame('pending_payment', OrderStatus::PendingPayment->value);
        self::assertSame('confirmed', OrderStatus::Confirmed->value);
        self::assertSame('fulfilled', OrderStatus::Fulfilled->value);
        self::assertSame('refunded', OrderStatus::Refunded->value);
        self::assertSame('failed', OrderStatus::Failed->value);
        self::assertSame('cancelled', OrderStatus::Cancelled->value);
    }

    #[Test]
    public function test_from_valid_value(): void
    {
        self::assertSame(OrderStatus::Cart, OrderStatus::from('cart'));
        self::assertSame(OrderStatus::Fulfilled, OrderStatus::from('fulfilled'));
    }

    #[Test]
    public function test_try_from_invalid_value_returns_null(): void
    {
        self::assertNull(OrderStatus::tryFrom('nonexistent'));
    }
}
