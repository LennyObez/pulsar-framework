<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Payment;
use Pulsar\Extension\Payments\Domain\PaymentMethod;
use Pulsar\Extension\Payments\Domain\PaymentStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;

use function strlen;

final class PaymentTest extends TestCase
{
    #[Test]
    public function createReturnsPaymentInPendingState(): void
    {
        $payment = Payment::create(
            amount: Money::of(5000, Currency::USD),
            method: PaymentMethod::Card,
            gateway: 'stripe',
            customerId: 'cust-1',
            idempotencyKey: 'key-1',
        );

        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertSame(5000, $payment->amount->amount);
        self::assertSame(PaymentMethod::Card, $payment->method);
        self::assertSame('stripe', $payment->gateway);
        self::assertSame('cust-1', $payment->customerId);
        self::assertNull($payment->subscriptionId);
        self::assertNull($payment->invoiceId);
        self::assertNull($payment->failureReason);
        self::assertSame(32, strlen($payment->id));
    }

    #[Test]
    public function createWithOptionalParams(): void
    {
        $payment = Payment::create(
            amount: Money::of(1000, Currency::EUR),
            method: PaymentMethod::Sepa,
            gateway: 'sepa',
            customerId: 'cust-2',
            idempotencyKey: 'key-2',
            subscriptionId: 'sub-1',
            invoiceId: 'inv-1',
            metadata: ['note' => 'test'],
        );

        self::assertSame('sub-1', $payment->subscriptionId);
        self::assertSame('inv-1', $payment->invoiceId);
        self::assertSame(['note' => 'test'], $payment->metadata);
    }

    #[Test]
    #[DataProvider('validTransitionsProvider')]
    public function transitionToAcceptsValidTransitions(PaymentStatus $from, PaymentStatus $to): void
    {
        $payment = $this->paymentWithStatus($from);
        $transitioned = $payment->transitionTo($to);

        self::assertSame($to, $transitioned->status);
        self::assertSame($from, $payment->status, 'original is immutable');
    }

    /**
     * @return iterable<string, array{PaymentStatus, PaymentStatus}>
     */
    public static function validTransitionsProvider(): iterable
    {
        yield 'pending->completed' => [PaymentStatus::Pending, PaymentStatus::Completed];
        yield 'pending->failed' => [PaymentStatus::Pending, PaymentStatus::Failed];
        yield 'pending->cancelled' => [PaymentStatus::Pending, PaymentStatus::Cancelled];
        yield 'completed->refunded' => [PaymentStatus::Completed, PaymentStatus::Refunded];
        yield 'completed->partially_refunded' => [PaymentStatus::Completed, PaymentStatus::PartiallyRefunded];
        yield 'partially_refunded->refunded' => [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded];
    }

    #[Test]
    #[DataProvider('invalidTransitionsProvider')]
    public function transitionToRejectsInvalidTransitions(PaymentStatus $from, PaymentStatus $to): void
    {
        $payment = $this->paymentWithStatus($from);

        $this->expectException(PaymentException::class);
        (void) $payment->transitionTo($to);
    }

    /**
     * @return iterable<string, array{PaymentStatus, PaymentStatus}>
     */
    public static function invalidTransitionsProvider(): iterable
    {
        yield 'pending->refunded' => [PaymentStatus::Pending, PaymentStatus::Refunded];
        yield 'failed->completed' => [PaymentStatus::Failed, PaymentStatus::Completed];
        yield 'cancelled->completed' => [PaymentStatus::Cancelled, PaymentStatus::Completed];
        yield 'refunded->completed' => [PaymentStatus::Refunded, PaymentStatus::Completed];
    }

    #[Test]
    public function transitionToPreservesFailureReason(): void
    {
        $payment = $this->paymentWithStatus(PaymentStatus::Pending);
        $failed = $payment->transitionTo(PaymentStatus::Failed, 'Card declined');

        self::assertSame('Card declined', $failed->failureReason);
    }

    private function paymentWithStatus(PaymentStatus $status): Payment
    {
        return new Payment(
            id: 'pay-1',
            amount: Money::of(1000, Currency::USD),
            status: $status,
            method: PaymentMethod::Card,
            gateway: 'stripe',
            customerId: 'cust-1',
            subscriptionId: null,
            invoiceId: null,
            idempotencyKey: 'key-1',
            createdAt: new DateTimeImmutable(),
        );
    }
}
