<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PricingPlan;
use Pulsar\Extension\Payments\Domain\PricingTier;

final class PricingPlanTest extends TestCase
{
    #[Test]
    public function effectivePriceReturnsFullPriceWithNoDiscount(): void
    {
        $plan = new PricingPlan(
            id: 'plan-1',
            name: 'Basic',
            price: Money::of(2999, Currency::USD),
            billingCycle: BillingCycle::Monthly,
            currency: Currency::USD,
            trialDays: 0,
            discountBasisPoints: null,
            tiers: [],
            active: true,
        );

        self::assertSame(2999, $plan->effectivePrice()->amount);
    }

    #[Test]
    public function effectivePriceReturnsFullPriceWithZeroDiscount(): void
    {
        $plan = new PricingPlan(
            id: 'plan-2',
            name: 'Pro',
            price: Money::of(5000, Currency::USD),
            billingCycle: BillingCycle::Annual,
            currency: Currency::USD,
            trialDays: 14,
            discountBasisPoints: 0,
            tiers: [],
            active: true,
        );

        self::assertSame(5000, $plan->effectivePrice()->amount);
    }

    #[Test]
    public function effectivePriceAppliesDiscount(): void
    {
        $plan = new PricingPlan(
            id: 'plan-3',
            name: 'Enterprise',
            price: Money::of(10000, Currency::USD),
            billingCycle: BillingCycle::Annual,
            currency: Currency::USD,
            trialDays: 30,
            discountBasisPoints: 2000, // 20%
            tiers: [],
            active: true,
        );

        self::assertSame(8000, $plan->effectivePrice()->amount);
    }

    #[Test]
    public function hasTrialReturnsTrueWhenTrialDaysPositive(): void
    {
        $plan = new PricingPlan(
            id: 'p',
            name: 'P',
            price: Money::of(100, Currency::USD),
            billingCycle: BillingCycle::Monthly,
            currency: Currency::USD,
            trialDays: 14,
            discountBasisPoints: null,
            tiers: [],
            active: true,
        );

        self::assertTrue($plan->hasTrial());
    }

    #[Test]
    public function hasTrialReturnsFalseWhenTrialDaysZero(): void
    {
        $plan = new PricingPlan(
            id: 'p',
            name: 'P',
            price: Money::of(100, Currency::USD),
            billingCycle: BillingCycle::Monthly,
            currency: Currency::USD,
            trialDays: 0,
            discountBasisPoints: null,
            tiers: [],
            active: true,
        );

        self::assertFalse($plan->hasTrial());
    }

    #[Test]
    public function tiersCanBeAttached(): void
    {
        $plan = new PricingPlan(
            id: 'tiered',
            name: 'Tiered',
            price: Money::of(0, Currency::USD),
            billingCycle: BillingCycle::Monthly,
            currency: Currency::USD,
            trialDays: 0,
            discountBasisPoints: null,
            tiers: [
                new PricingTier(100, Money::of(50, Currency::USD), null),
                new PricingTier(1000, Money::of(40, Currency::USD), Money::of(500, Currency::USD)),
            ],
            active: true,
        );

        self::assertCount(2, $plan->tiers);
        self::assertSame(100, $plan->tiers[0]->upTo);
        self::assertNotNull($plan->tiers[1]->flatFee);
        self::assertSame(500, $plan->tiers[1]->flatFee->amount);
    }
}
