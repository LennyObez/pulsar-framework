<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Extension\Payments\Internal\Billing\ProrationCalculator;

final class ProrationCalculatorTest extends TestCase
{
    #[Test]
    public function calculateReturnsBothChargeAndCredit(): void
    {
        $calculator = new ProrationCalculator();
        $sub = $this->buildSubscription(
            amount: Money::of(3000, Currency::USD),
            periodStart: new DateTimeImmutable('2026-01-01'),
            periodEnd: new DateTimeImmutable('2026-01-31'),
        );

        $result = $calculator->calculate(
            $sub,
            Money::of(6000, Currency::USD),
            new DateTimeImmutable('2026-01-16'), // mid-cycle
        );

        self::assertArrayHasKey('charge', $result);
        self::assertArrayHasKey('credit', $result);
        self::assertInstanceOf(Money::class, $result['charge']);
        self::assertInstanceOf(Money::class, $result['credit']);
    }

    #[Test]
    public function calculateAtStartOfPeriodGivesFullCredit(): void
    {
        $calculator = new ProrationCalculator();
        $sub = $this->buildSubscription(
            amount: Money::of(3000, Currency::USD),
            periodStart: new DateTimeImmutable('2026-01-01'),
            periodEnd: new DateTimeImmutable('2026-01-31'),
        );

        $result = $calculator->calculate(
            $sub,
            Money::of(6000, Currency::USD),
            new DateTimeImmutable('2026-01-01'),
        );

        // At start: remaining = full period, so credit = full old price, charge = full new price
        self::assertSame(3000, $result['credit']->amount);
    }

    #[Test]
    public function calculateAtEndOfPeriodGivesZeroCredit(): void
    {
        $calculator = new ProrationCalculator();
        $sub = $this->buildSubscription(
            amount: Money::of(3000, Currency::USD),
            periodStart: new DateTimeImmutable('2026-01-01'),
            periodEnd: new DateTimeImmutable('2026-01-31'),
        );

        $result = $calculator->calculate(
            $sub,
            Money::of(6000, Currency::USD),
            new DateTimeImmutable('2026-01-31'),
        );

        self::assertSame(0, $result['credit']->amount);
        self::assertSame(0, $result['charge']->amount);
    }

    private function buildSubscription(
        Money $amount,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
    ): Subscription {
        return new Subscription(
            id: 'sub-1',
            customerId: 'cust-1',
            planId: 'plan-old',
            status: SubscriptionStatus::Active,
            billingCycle: BillingCycle::Monthly,
            amount: $amount,
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: $periodStart,
            currentPeriodEnd: $periodEnd,
            trialEnd: null,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: $periodStart,
            updatedAt: $periodStart,
        );
    }
}
