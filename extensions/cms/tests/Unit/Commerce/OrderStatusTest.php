<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\OrderStatus;

#[CoversClass(OrderStatus::class)]
final class OrderStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{OrderStatus, bool}>
     */
    public static function terminalStatusProvider(): iterable
    {
        yield 'cart is not terminal' => [OrderStatus::Cart, false];
        yield 'pending_payment is not terminal' => [OrderStatus::PendingPayment, false];
        yield 'confirmed is not terminal' => [OrderStatus::Confirmed, false];
        yield 'fulfilled is not terminal' => [OrderStatus::Fulfilled, false];
        yield 'failed is not terminal' => [OrderStatus::Failed, false];
        yield 'refunded is terminal' => [OrderStatus::Refunded, true];
        yield 'cancelled is terminal' => [OrderStatus::Cancelled, true];
    }

    #[Test]
    #[DataProvider('terminalStatusProvider')]
    public function isTerminal_returns_expected(OrderStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isTerminal());
    }

    #[Test]
    public function label_returns_human_readable_string_for_all_cases(): void
    {
        $expectedLabels = [
            'Cart' => OrderStatus::Cart,
            'Pending Payment' => OrderStatus::PendingPayment,
            'Confirmed' => OrderStatus::Confirmed,
            'Fulfilled' => OrderStatus::Fulfilled,
            'Refunded' => OrderStatus::Refunded,
            'Failed' => OrderStatus::Failed,
            'Cancelled' => OrderStatus::Cancelled,
        ];

        foreach ($expectedLabels as $label => $status) {
            self::assertSame($label, $status->label());
        }
    }

    #[Test]
    public function all_cases_have_string_values(): void
    {
        foreach (OrderStatus::cases() as $case) {
            self::assertNotEmpty($case->value);
        }
    }
}
