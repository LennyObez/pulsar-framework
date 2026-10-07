<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

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
    // ── canTransition — valid transitions ────────────────────────────

    #[Test]
    #[DataProvider('validTransitionsProvider')]
    public function canTransitionValidPair(OrderStatus $from, OrderStatus $to): void
    {
        self::assertTrue(OrderStatusStateMachine::canTransition($from, $to));
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function validTransitionsProvider(): iterable
    {
        yield 'Cart -> PendingPayment' => [OrderStatus::Cart, OrderStatus::PendingPayment];
        yield 'Cart -> Cancelled' => [OrderStatus::Cart, OrderStatus::Cancelled];
        yield 'PendingPayment -> Confirmed' => [OrderStatus::PendingPayment, OrderStatus::Confirmed];
        yield 'PendingPayment -> Failed' => [OrderStatus::PendingPayment, OrderStatus::Failed];
        yield 'PendingPayment -> Cancelled' => [OrderStatus::PendingPayment, OrderStatus::Cancelled];
        yield 'Confirmed -> Fulfilled' => [OrderStatus::Confirmed, OrderStatus::Fulfilled];
        yield 'Confirmed -> Cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled];
        yield 'Fulfilled -> Refunded' => [OrderStatus::Fulfilled, OrderStatus::Refunded];
        yield 'Failed -> Cancelled' => [OrderStatus::Failed, OrderStatus::Cancelled];
    }

    // ── canTransition — invalid transitions ─────────────────────────

    #[Test]
    #[DataProvider('invalidTransitionsProvider')]
    public function cannotTransitionInvalidPair(OrderStatus $from, OrderStatus $to): void
    {
        self::assertFalse(OrderStatusStateMachine::canTransition($from, $to));
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function invalidTransitionsProvider(): iterable
    {
        yield 'Cart -> Confirmed' => [OrderStatus::Cart, OrderStatus::Confirmed];
        yield 'Cart -> Fulfilled' => [OrderStatus::Cart, OrderStatus::Fulfilled];
        yield 'Cart -> Refunded' => [OrderStatus::Cart, OrderStatus::Refunded];
        yield 'PendingPayment -> Fulfilled' => [OrderStatus::PendingPayment, OrderStatus::Fulfilled];
        yield 'PendingPayment -> Refunded' => [OrderStatus::PendingPayment, OrderStatus::Refunded];
        yield 'Confirmed -> PendingPayment' => [OrderStatus::Confirmed, OrderStatus::PendingPayment];
        yield 'Confirmed -> Refunded' => [OrderStatus::Confirmed, OrderStatus::Refunded];
        yield 'Fulfilled -> Confirmed' => [OrderStatus::Fulfilled, OrderStatus::Confirmed];
        yield 'Fulfilled -> Cancelled' => [OrderStatus::Fulfilled, OrderStatus::Cancelled];
        yield 'Failed -> Confirmed' => [OrderStatus::Failed, OrderStatus::Confirmed];
        yield 'Failed -> PendingPayment' => [OrderStatus::Failed, OrderStatus::PendingPayment];
    }

    // ── canTransition — self-transition forbidden ───────────────────

    #[Test]
    #[DataProvider('allStatusesProvider')]
    public function cannotTransitionToSelf(OrderStatus $status): void
    {
        self::assertFalse(OrderStatusStateMachine::canTransition($status, $status));
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function allStatusesProvider(): iterable
    {
        foreach (OrderStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    // ── Terminal states cannot transition further ────────────────────

    #[Test]
    #[DataProvider('terminalToAnyProvider')]
    public function terminalStatesCannotTransition(OrderStatus $terminal, OrderStatus $target): void
    {
        self::assertFalse(OrderStatusStateMachine::canTransition($terminal, $target));
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function terminalToAnyProvider(): iterable
    {
        $terminals = [OrderStatus::Refunded, OrderStatus::Cancelled];
        $allStatuses = OrderStatus::cases();

        foreach ($terminals as $terminal) {
            foreach ($allStatuses as $target) {
                if ($terminal === $target) {
                    continue;
                }
                yield "{$terminal->value} -> {$target->value}" => [$terminal, $target];
            }
        }
    }

    // ── transition() — returns new status on success ────────────────

    #[Test]
    public function transitionReturnsNewStatus(): void
    {
        $result = OrderStatusStateMachine::transition(OrderStatus::Cart, OrderStatus::PendingPayment);

        self::assertSame(OrderStatus::PendingPayment, $result);
    }

    // ── transition() — throws on invalid transition ─────────────────

    #[Test]
    public function transitionThrowsOnInvalid(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains("Invalid status transition from 'cart' to 'fulfilled'");

        OrderStatusStateMachine::transition(OrderStatus::Cart, OrderStatus::Fulfilled);
    }

    #[Test]
    public function transitionThrowsForTerminalState(): void
    {
        $this->expectException(CmsException::class);

        OrderStatusStateMachine::transition(OrderStatus::Refunded, OrderStatus::Cart);
    }

    // ── Cancelled reachable from Cart, PendingPayment, Confirmed ────

    #[Test]
    #[DataProvider('cancellableStatusesProvider')]
    public function cancelledReachableFromNonTerminal(OrderStatus $from): void
    {
        self::assertTrue(OrderStatusStateMachine::canTransition($from, OrderStatus::Cancelled));
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function cancellableStatusesProvider(): iterable
    {
        yield 'Cart' => [OrderStatus::Cart];
        yield 'PendingPayment' => [OrderStatus::PendingPayment];
        yield 'Confirmed' => [OrderStatus::Confirmed];
        yield 'Failed' => [OrderStatus::Failed];
    }

    // ── Refunded only from Fulfilled ────────────────────────────────

    #[Test]
    public function refundedOnlyFromFulfilled(): void
    {
        self::assertTrue(OrderStatusStateMachine::canTransition(OrderStatus::Fulfilled, OrderStatus::Refunded));

        foreach (OrderStatus::cases() as $status) {
            if ($status === OrderStatus::Fulfilled || $status === OrderStatus::Refunded) {
                continue;
            }
            self::assertFalse(
                OrderStatusStateMachine::canTransition($status, OrderStatus::Refunded),
                "{$status->value} should not be able to transition to Refunded",
            );
        }
    }
}
