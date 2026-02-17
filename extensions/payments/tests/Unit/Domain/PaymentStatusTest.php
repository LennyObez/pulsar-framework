<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\PaymentStatus;

final class PaymentStatusTest extends TestCase
{
    #[Test]
    #[DataProvider('canTransitionProvider')]
    public function canTransitionToReturnsExpectedResult(PaymentStatus $from, PaymentStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    /**
     * @return iterable<string, array{PaymentStatus, PaymentStatus, bool}>
     */
    public static function canTransitionProvider(): iterable
    {
        yield 'pending->completed' => [PaymentStatus::Pending, PaymentStatus::Completed, true];
        yield 'pending->failed' => [PaymentStatus::Pending, PaymentStatus::Failed, true];
        yield 'pending->cancelled' => [PaymentStatus::Pending, PaymentStatus::Cancelled, true];
        yield 'pending->refunded' => [PaymentStatus::Pending, PaymentStatus::Refunded, false];
        yield 'completed->refunded' => [PaymentStatus::Completed, PaymentStatus::Refunded, true];
        yield 'completed->partially_refunded' => [PaymentStatus::Completed, PaymentStatus::PartiallyRefunded, true];
        yield 'completed->failed' => [PaymentStatus::Completed, PaymentStatus::Failed, false];
        yield 'partially_refunded->refunded' => [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded, true];
        yield 'partially_refunded->completed' => [PaymentStatus::PartiallyRefunded, PaymentStatus::Completed, false];
        yield 'failed->any' => [PaymentStatus::Failed, PaymentStatus::Completed, false];
        yield 'cancelled->any' => [PaymentStatus::Cancelled, PaymentStatus::Pending, false];
        yield 'refunded->any' => [PaymentStatus::Refunded, PaymentStatus::Completed, false];
    }

    #[Test]
    public function terminalStatesCannotTransition(): void
    {
        $terminals = [PaymentStatus::Failed, PaymentStatus::Cancelled, PaymentStatus::Refunded];

        foreach ($terminals as $terminal) {
            foreach (PaymentStatus::cases() as $target) {
                self::assertFalse($terminal->canTransitionTo($target), "$terminal->value should not transition to $target->value");
            }
        }
    }
}
