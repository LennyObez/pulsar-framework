<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\MobileStore;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;

final class SubscriptionTest extends TestCase
{
    #[Test]
    public function createReturnsActiveSubscriptionWithoutTrial(): void
    {
        $sub = Subscription::create(
            customerId: 'cust-1',
            planId: 'plan-pro',
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(2999, Currency::USD),
            gateway: 'stripe',
        );

        self::assertSame(SubscriptionStatus::Active, $sub->status);
        self::assertSame('cust-1', $sub->customerId);
        self::assertSame('plan-pro', $sub->planId);
        self::assertSame(BillingCycle::Monthly, $sub->billingCycle);
        self::assertSame(2999, $sub->amount->amount);
        self::assertSame('stripe', $sub->gateway);
        self::assertNull($sub->trialEnd);
        self::assertNull($sub->cancelledAt);
        self::assertNotNull($sub->currentPeriodStart);
        self::assertNotNull($sub->currentPeriodEnd);
        self::assertFalse($sub->isMobile());
    }

    #[Test]
    public function createWithTrialReturnsTrialingStatus(): void
    {
        $trialEnd = new DateTimeImmutable('+14 days');

        $sub = Subscription::create(
            customerId: 'cust-2',
            planId: 'plan-basic',
            billingCycle: BillingCycle::Annual,
            amount: Money::of(9999, Currency::EUR),
            gateway: 'paypal',
            trialEnd: $trialEnd,
        );

        self::assertSame(SubscriptionStatus::Trialing, $sub->status);
        self::assertSame($trialEnd, $sub->trialEnd);
    }

    #[Test]
    public function createMobileReturnsMobileSubscription(): void
    {
        $sub = Subscription::createMobile(
            customerId: 'cust-3',
            planId: 'plan-premium',
            store: MobileStore::Apple,
            purchaseTokenHash: 'abc123hash',
            originalTransactionId: 'txn-orig-1',
        );

        self::assertSame(SubscriptionStatus::Active, $sub->status);
        self::assertTrue($sub->isMobile());
        self::assertSame(MobileStore::Apple, $sub->mobileStore);
        self::assertSame('abc123hash', $sub->purchaseTokenHash);
        self::assertSame('txn-orig-1', $sub->originalTransactionId);
        self::assertNull($sub->gateway);
        self::assertSame(0, $sub->amount->amount);
    }

    #[Test]
    public function withStatusTransitionsImmutably(): void
    {
        $sub = Subscription::create(
            customerId: 'cust-1',
            planId: 'plan-1',
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(1000, Currency::USD),
            gateway: 'stripe',
        );

        $cancelled = $sub->withStatus(SubscriptionStatus::Cancelled);

        self::assertSame(SubscriptionStatus::Cancelled, $cancelled->status);
        self::assertNotNull($cancelled->cancelledAt);
        self::assertSame(SubscriptionStatus::Active, $sub->status, 'original is immutable');
    }

    #[Test]
    public function hasAccessReturnsTrueForActiveStatuses(): void
    {
        $sub = $this->subscriptionWithStatus(SubscriptionStatus::Active);
        self::assertTrue($sub->hasAccess());

        $sub = $this->subscriptionWithStatus(SubscriptionStatus::Trialing);
        self::assertTrue($sub->hasAccess());

        $sub = $this->subscriptionWithStatus(SubscriptionStatus::GracePeriod);
        self::assertTrue($sub->hasAccess());

        $sub = $this->subscriptionWithStatus(SubscriptionStatus::PastDue);
        self::assertTrue($sub->hasAccess());
    }

    #[Test]
    public function hasAccessReturnsFalseForInactiveStatuses(): void
    {
        $sub = $this->subscriptionWithStatus(SubscriptionStatus::Expired);
        self::assertFalse($sub->hasAccess());

        $sub = $this->subscriptionWithStatus(SubscriptionStatus::Paused);
        self::assertFalse($sub->hasAccess());

        $sub = $this->subscriptionWithStatus(SubscriptionStatus::Revoked);
        self::assertFalse($sub->hasAccess());
    }

    #[Test]
    public function cancelledSubHasAccessIfPeriodNotEnded(): void
    {
        $sub = new Subscription(
            id: 'sub-1',
            customerId: 'c',
            planId: 'p',
            status: SubscriptionStatus::Cancelled,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(100, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: new DateTimeImmutable('-1 day'),
            currentPeriodEnd: new DateTimeImmutable('+30 days'),
            trialEnd: null,
            cancelledAt: new DateTimeImmutable(),
            gracePeriodUntil: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        self::assertTrue($sub->hasAccess());
    }

    #[Test]
    public function cancelledSubHasNoAccessIfPeriodEnded(): void
    {
        $sub = new Subscription(
            id: 'sub-1',
            customerId: 'c',
            planId: 'p',
            status: SubscriptionStatus::Cancelled,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(100, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: new DateTimeImmutable('-31 days'),
            currentPeriodEnd: new DateTimeImmutable('-1 day'),
            trialEnd: null,
            cancelledAt: new DateTimeImmutable('-1 day'),
            gracePeriodUntil: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        self::assertFalse($sub->hasAccess());
    }

    private function subscriptionWithStatus(SubscriptionStatus $status): Subscription
    {
        return new Subscription(
            id: 'sub-test',
            customerId: 'cust',
            planId: 'plan',
            status: $status,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(1000, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: new DateTimeImmutable(),
            currentPeriodEnd: new DateTimeImmutable('+30 days'),
            trialEnd: null,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
