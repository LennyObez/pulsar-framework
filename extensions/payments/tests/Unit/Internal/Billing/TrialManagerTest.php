<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Internal\Billing\TrialManager;

final class TrialManagerTest extends TestCase
{
    private SubscriptionRepositoryInterface&Stub $repository;
    private TrialManager $trialManager;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(SubscriptionRepositoryInterface::class);
        $this->repository->method('findByCustomer')->willReturn([]);

        $config = PaymentsConfig::fromArray(['trial_max_days' => 30]);

        $this->trialManager = new TrialManager(
            $this->repository,
            new NullLogger(),
            $config,
        );
    }

    #[Test]
    public function startTrialCreatesTrialingSubscription(): void
    {
        $sub = $this->trialManager->startTrial(
            customerId: 'cust-1',
            planId: 'plan-pro',
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(2999, Currency::USD),
            gateway: 'stripe',
            trialDays: 14,
        );

        self::assertSame(SubscriptionStatus::Trialing, $sub->status);
        self::assertNotNull($sub->trialEnd);
    }

    #[Test]
    public function startTrialRejectsExceedingMaxDays(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('exceeds maximum');

        $this->trialManager->startTrial(
            customerId: 'cust-1',
            planId: 'plan-pro',
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(2999, Currency::USD),
            gateway: 'stripe',
            trialDays: 60,
        );
    }

    #[Test]
    public function startTrialRejectsZeroDays(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('must be positive');

        $this->trialManager->startTrial(
            customerId: 'cust-1',
            planId: 'plan-pro',
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(2999, Currency::USD),
            gateway: 'stripe',
            trialDays: 0,
        );
    }

    #[Test]
    public function startTrialRejectsDuplicateTrialForSamePlan(): void
    {
        $existingSub = new Subscription(
            id: 'existing',
            customerId: 'cust-1',
            planId: 'plan-pro',
            status: SubscriptionStatus::Expired,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(2999, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: new DateTimeImmutable(),
            currentPeriodEnd: new DateTimeImmutable(),
            trialEnd: new DateTimeImmutable('-1 day'), // had a trial
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $repository = $this->createStub(SubscriptionRepositoryInterface::class);
        $repository->method('findByCustomer')->willReturn([$existingSub]);
        $config = PaymentsConfig::fromArray(['trial_max_days' => 30]);

        $manager = new TrialManager($repository, new NullLogger(), $config);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('already used a trial');

        $manager->startTrial('cust-1', 'plan-pro', BillingCycle::Monthly, Money::of(2999, Currency::USD), 'stripe', 14);
    }

    #[Test]
    public function convertToPaidTransitionsTrialingToActive(): void
    {
        $sub = $this->buildSubscription(SubscriptionStatus::Trialing);
        $result = $this->trialManager->convertToPaid($sub);

        self::assertSame(SubscriptionStatus::Active, $result->status);
    }

    #[Test]
    public function convertToPaidRejectsNonTrialingSubscription(): void
    {
        $sub = $this->buildSubscription(SubscriptionStatus::Active);

        $this->expectException(PaymentException::class);
        $this->trialManager->convertToPaid($sub);
    }

    #[Test]
    public function expireTrialTransitionsTrialingToExpired(): void
    {
        $sub = $this->buildSubscription(SubscriptionStatus::Trialing);
        $result = $this->trialManager->expireTrial($sub);

        self::assertSame(SubscriptionStatus::Expired, $result->status);
    }

    #[Test]
    public function expireTrialNoOpsForNonTrialing(): void
    {
        $sub = $this->buildSubscription(SubscriptionStatus::Active);
        $result = $this->trialManager->expireTrial($sub);

        self::assertSame(SubscriptionStatus::Active, $result->status);
    }

    private function buildSubscription(SubscriptionStatus $status): Subscription
    {
        return new Subscription(
            id: 'sub-trial',
            customerId: 'cust-1',
            planId: 'plan-1',
            status: $status,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(2999, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: new DateTimeImmutable(),
            currentPeriodEnd: new DateTimeImmutable('+30 days'),
            trialEnd: new DateTimeImmutable('+14 days'),
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
