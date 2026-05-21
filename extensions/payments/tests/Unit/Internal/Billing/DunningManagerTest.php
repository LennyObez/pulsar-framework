<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Pulsar\Extension\Payments\Internal\Billing\DunningManager;

final class DunningManagerTest extends TestCase
{
    private SubscriptionRepositoryInterface&Stub $repository;
    private DunningManager $dunning;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(SubscriptionRepositoryInterface::class);
        $config = PaymentsConfig::fromArray(['dunning_max_retries' => 4]);

        $this->dunning = new DunningManager(
            $this->repository,
            new NullLogger(),
            $config,
        );
    }

    #[Test]
    public function enterDunningTransitionsToPastDue(): void
    {
        $sub = $this->buildSubscription(SubscriptionStatus::Active);
        $result = $this->dunning->enterDunning($sub);

        self::assertSame(SubscriptionStatus::PastDue, $result->status);
    }

    #[Test]
    public function shouldExpireReturnsTrueWhenRetryCountReachesMax(): void
    {
        self::assertFalse($this->dunning->shouldExpire(0));
        self::assertFalse($this->dunning->shouldExpire(3));
        self::assertTrue($this->dunning->shouldExpire(4));
        self::assertTrue($this->dunning->shouldExpire(10));
    }

    #[Test]
    public function expireTransitionsToExpired(): void
    {
        $sub = $this->buildSubscription(SubscriptionStatus::PastDue);
        $result = $this->dunning->expire($sub);

        self::assertSame(SubscriptionStatus::Expired, $result->status);
    }

    #[Test]
    #[DataProvider('retryIntervalProvider')]
    public function nextRetryIntervalDaysReturnsCorrectDays(int $retryCount, int $expected): void
    {
        self::assertSame($expected, $this->dunning->nextRetryIntervalDays($retryCount));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function retryIntervalProvider(): iterable
    {
        yield 'first retry' => [0, 1];
        yield 'second retry' => [1, 3];
        yield 'third retry' => [2, 7];
        yield 'fourth retry' => [3, 14];
        yield 'beyond max clamps to last' => [10, 14];
    }

    private function buildSubscription(SubscriptionStatus $status): Subscription
    {
        return new Subscription(
            id: 'sub-dunning',
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
            trialEnd: null,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
