<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\OrderStatusStateMachine;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(OrderStatusStateMachine::class)]
final class OrderStatusStateMachineTest extends TestCase
{
    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function validTransitionsProvider(): iterable
    {
        yield 'cart to pending_payment' => [OrderStatus::Cart, OrderStatus::PendingPayment];
        yield 'cart to cancelled' => [OrderStatus::Cart, OrderStatus::Cancelled];
        yield 'pending_payment to confirmed' => [OrderStatus::PendingPayment, OrderStatus::Confirmed];
        yield 'pending_payment to failed' => [OrderStatus::PendingPayment, OrderStatus::Failed];
        yield 'pending_payment to cancelled' => [OrderStatus::PendingPayment, OrderStatus::Cancelled];
        yield 'confirmed to fulfilled' => [OrderStatus::Confirmed, OrderStatus::Fulfilled];
        yield 'confirmed to cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled];
        yield 'fulfilled to refunded' => [OrderStatus::Fulfilled, OrderStatus::Refunded];
        yield 'failed to cancelled' => [OrderStatus::Failed, OrderStatus::Cancelled];
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function invalidTransitionsProvider(): iterable
    {
        yield 'cart to confirmed' => [OrderStatus::Cart, OrderStatus::Confirmed];
        yield 'cart to fulfilled' => [OrderStatus::Cart, OrderStatus::Fulfilled];
        yield 'cart to refunded' => [OrderStatus::Cart, OrderStatus::Refunded];
        yield 'pending_payment to fulfilled' => [OrderStatus::PendingPayment, OrderStatus::Fulfilled];
        yield 'confirmed to pending_payment' => [OrderStatus::Confirmed, OrderStatus::PendingPayment];
        yield 'fulfilled to confirmed' => [OrderStatus::Fulfilled, OrderStatus::Confirmed];
        yield 'refunded to any' => [OrderStatus::Refunded, OrderStatus::Cart];
        yield 'cancelled to any' => [OrderStatus::Cancelled, OrderStatus::Cart];
        yield 'failed to confirmed' => [OrderStatus::Failed, OrderStatus::Confirmed];
    }

    #[Test]
    #[DataProvider('validTransitionsProvider')]
    public function canTransition_returns_true_for_valid_transitions(OrderStatus $from, OrderStatus $to): void
    {
        self::assertTrue(OrderStatusStateMachine::canTransition($from, $to));
    }

    #[Test]
    #[DataProvider('invalidTransitionsProvider')]
    public function canTransition_returns_false_for_invalid_transitions(OrderStatus $from, OrderStatus $to): void
    {
        self::assertFalse(OrderStatusStateMachine::canTransition($from, $to));
    }

    #[Test]
    public function canTransition_returns_false_for_same_status(): void
    {
        foreach (OrderStatus::cases() as $status) {
            self::assertFalse(
                OrderStatusStateMachine::canTransition($status, $status),
                "Same-status transition should be rejected for {$status->value}",
            );
        }
    }

    #[Test]
    #[DataProvider('validTransitionsProvider')]
    public function transition_returns_target_status_for_valid_transitions(OrderStatus $from, OrderStatus $to): void
    {
        $result = OrderStatusStateMachine::transition($from, $to);

        self::assertSame($to, $result);
    }

    #[Test]
    public function transition_throws_for_invalid_transition(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains("Invalid status transition from 'cart' to 'confirmed'");

        OrderStatusStateMachine::transition(OrderStatus::Cart, OrderStatus::Confirmed);
    }

    #[Test]
    public function terminal_statuses_cannot_transition_to_anything(): void
    {
        $terminals = [OrderStatus::Refunded, OrderStatus::Cancelled];

        foreach ($terminals as $terminal) {
            foreach (OrderStatus::cases() as $target) {
                self::assertFalse(
                    OrderStatusStateMachine::canTransition($terminal, $target),
                    "Terminal status {$terminal->value} should not transition to {$target->value}",
                );
            }
        }
    }
}
