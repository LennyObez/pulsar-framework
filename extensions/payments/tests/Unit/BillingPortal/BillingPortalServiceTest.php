<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\BillingPortal;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\BillingPortal\BillingPortalRepositoryInterface;
use Pulsar\Extension\Payments\BillingPortal\BillingPortalService;
use Pulsar\Extension\Payments\BillingPortal\PaymentMethodSummary;
use Pulsar\Extension\Payments\Contracts\SubscriptionManagerInterface;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;

final class BillingPortalServiceTest extends TestCase
{
    private BillingPortalService $service;
    private SubscriptionManagerInterface&Stub $subscriptionManager;
    private BillingPortalRepositoryInterface&Stub $repository;

    protected function setUp(): void
    {
        $this->subscriptionManager = $this->createStub(SubscriptionManagerInterface::class);
        $this->repository = $this->createStub(BillingPortalRepositoryInterface::class);
        $this->service = new BillingPortalService($this->subscriptionManager, $this->repository);
    }

    #[Test]
    public function getSummaryReturnsCompleteSummary(): void
    {
        $this->repository->method('getActiveSubscriptions')->willReturn([]);
        $this->repository->method('getRecentInvoices')->willReturn([]);
        $this->repository->method('getPaymentMethods')->willReturn([
            new PaymentMethodSummary('pm-1', 'card', '4242', 'visa', 12, 2028, true),
        ]);

        $summary = $this->service->getSummary('cust-1');

        self::assertSame('cust-1', $summary->customerId);
        self::assertFalse($summary->hasActiveSubscriptions());
        self::assertCount(0, $summary->recentInvoices);
        self::assertCount(1, $summary->paymentMethods);
        self::assertNull($summary->nextPaymentDate);
    }

    #[Test]
    public function cancelSubscriptionThrowsWhenNotFound(): void
    {
        $this->repository->method('getSubscription')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Subscription not found');

        $this->service->cancelSubscription('cust-1', 'sub-nonexistent');
    }

    #[Test]
    public function cancelSubscriptionThrowsWhenNotOwnedByCustomer(): void
    {
        $now = new DateTimeImmutable();
        $sub = new Subscription(
            id: 'sub-1',
            customerId: 'other-customer',
            planId: 'plan-1',
            status: SubscriptionStatus::Active,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(999, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: $now,
            currentPeriodEnd: $now->modify('+30 days'),
            trialEnd: null,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repository->method('getSubscription')->willReturn($sub);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Subscription not found');

        $this->service->cancelSubscription('cust-1', 'sub-1');
    }

    #[Test]
    public function getInvoiceHistoryDelegatesToRepository(): void
    {
        $this->repository->method('getRecentInvoices')->willReturn([]);

        $invoices = $this->service->getInvoiceHistory('cust-1', 20, 0);

        self::assertSame([], $invoices);
    }
}
