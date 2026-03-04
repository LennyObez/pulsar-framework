<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Contracts\InvoiceGeneratorInterface;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Extension\Payments\Internal\Scheduler\RecurringInvoiceJob;
use Pulsar\Extension\Payments\Tax\TaxCalculationResult;
use Pulsar\Extension\Payments\Tax\TaxProviderInterface;

use function count;
use function is_array;

final class RecurringInvoiceJobTest extends TestCase
{
    private SubscriptionRepositoryInterface&Stub $subscriptionRepo;
    private TaxProviderInterface&Stub $taxProvider;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $this->taxProvider = $this->createStub(TaxProviderInterface::class);
        $this->logger = new NullLogger();
    }

    #[Test]
    public function generatesInvoiceForDueSubscription(): void
    {
        $today = new DateTimeImmutable('today');
        $subscription = $this->createActiveSubscription($today, 9900, Currency::USD);

        $this->subscriptionRepo->method('findDueForRenewal')->willReturn([$subscription]);

        $this->taxProvider->method('calculateTax')->willReturn(
            new TaxCalculationResult(
                taxAmount: Money::of(0, Currency::USD),
                rateBasisPoints: 0,
                jurisdiction: 'US-OR',
            ),
        );

        $generatedInvoice = Invoice::create(
            invoiceNumber: 'INV-2026-000001',
            customerId: $subscription->customerId,
            lineItems: [InvoiceLineItem::create('Subscription', 1, Money::of(9900, Currency::USD))],
            tax: Money::of(0, Currency::USD),
        );

        $invoiceGenerator = $this->createMock(InvoiceGeneratorInterface::class);
        $invoiceGenerator->expects(self::once())
            ->method('generate')
            ->with(
                $subscription->customerId,
                $subscription->id,
                self::callback(static function (array $lineItems): bool {
                    /** @var list<InvoiceLineItem> $lineItems */
                    return count($lineItems) === 1
                        && $lineItems[0]->quantity === 1
                        && $lineItems[0]->unitPrice->amount === 9900;
                }),
                self::isInstanceOf(Money::class),
                self::isInstanceOf(DateTimeImmutable::class),
                self::callback(static fn(mixed $v): bool => is_array($v)),
            )
            ->willReturn($generatedInvoice);

        $job = new RecurringInvoiceJob(
            subscriptionRepository: $this->subscriptionRepo,
            invoiceGenerator: $invoiceGenerator,
            taxProvider: $this->taxProvider,
            logger: $this->logger,
        );

        $count = ($job)();

        self::assertSame(1, $count);
    }

    #[Test]
    public function skipsNonActiveSubscriptions(): void
    {
        $today = new DateTimeImmutable('today');
        $cancelled = new Subscription(
            id: 'sub-cancelled',
            customerId: 'cust-1',
            planId: 'plan-basic',
            status: SubscriptionStatus::Cancelled,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(9900, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: $today->modify('-30 days'),
            currentPeriodEnd: $today,
            trialEnd: null,
            cancelledAt: $today,
            gracePeriodUntil: null,
            createdAt: $today->modify('-60 days'),
            updatedAt: $today,
        );

        $this->subscriptionRepo->method('findDueForRenewal')->willReturn([$cancelled]);

        $invoiceGenerator = $this->createStub(InvoiceGeneratorInterface::class);

        $job = new RecurringInvoiceJob(
            subscriptionRepository: $this->subscriptionRepo,
            invoiceGenerator: $invoiceGenerator,
            taxProvider: $this->taxProvider,
            logger: $this->logger,
        );

        $count = ($job)();

        self::assertSame(0, $count);
    }

    #[Test]
    public function skipsSubscriptionsWithNullPeriodEnd(): void
    {
        $today = new DateTimeImmutable('today');
        $noPeriodEnd = new Subscription(
            id: 'sub-no-end',
            customerId: 'cust-2',
            planId: 'plan-basic',
            status: SubscriptionStatus::Active,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(9900, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: $today->modify('-30 days'),
            currentPeriodEnd: null,
            trialEnd: null,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: $today->modify('-60 days'),
            updatedAt: $today,
        );

        $this->subscriptionRepo->method('findDueForRenewal')->willReturn([$noPeriodEnd]);

        $invoiceGenerator = $this->createStub(InvoiceGeneratorInterface::class);

        $job = new RecurringInvoiceJob(
            subscriptionRepository: $this->subscriptionRepo,
            invoiceGenerator: $invoiceGenerator,
            taxProvider: $this->taxProvider,
            logger: $this->logger,
        );

        $count = ($job)();

        self::assertSame(0, $count);
    }

    #[Test]
    public function skipsSubscriptionsNotDueToday(): void
    {
        $today = new DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');

        $notDue = new Subscription(
            id: 'sub-not-due',
            customerId: 'cust-3',
            planId: 'plan-basic',
            status: SubscriptionStatus::Active,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(9900, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: null,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: $today->modify('-30 days'),
            currentPeriodEnd: $tomorrow,
            trialEnd: null,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: $today->modify('-60 days'),
            updatedAt: $today,
        );

        $this->subscriptionRepo->method('findDueForRenewal')->willReturn([$notDue]);

        $invoiceGenerator = $this->createStub(InvoiceGeneratorInterface::class);

        $job = new RecurringInvoiceJob(
            subscriptionRepository: $this->subscriptionRepo,
            invoiceGenerator: $invoiceGenerator,
            taxProvider: $this->taxProvider,
            logger: $this->logger,
        );

        $count = ($job)();

        self::assertSame(0, $count);
    }

    #[Test]
    public function returnsZeroWhenNoSubscriptionsDue(): void
    {
        $this->subscriptionRepo->method('findDueForRenewal')->willReturn([]);

        $invoiceGenerator = $this->createStub(InvoiceGeneratorInterface::class);

        $job = new RecurringInvoiceJob(
            subscriptionRepository: $this->subscriptionRepo,
            invoiceGenerator: $invoiceGenerator,
            taxProvider: $this->taxProvider,
            logger: $this->logger,
        );

        $count = ($job)();

        self::assertSame(0, $count);
    }

    #[Test]
    public function generatesInvoicesForMultipleDueSubscriptions(): void
    {
        $today = new DateTimeImmutable('today');

        $sub1 = $this->createActiveSubscription($today, 9900, Currency::USD);
        $sub2 = $this->createActiveSubscription($today, 19900, Currency::EUR);

        $this->subscriptionRepo->method('findDueForRenewal')->willReturn([$sub1, $sub2]);

        $this->taxProvider->method('calculateTax')->willReturn(
            new TaxCalculationResult(
                taxAmount: Money::of(0, Currency::USD),
                rateBasisPoints: 0,
                jurisdiction: 'US',
            ),
        );

        $invoiceGenerator = $this->createMock(InvoiceGeneratorInterface::class);
        $invoiceGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturn(
                Invoice::create('INV-001', 'cust', [], Money::of(0, Currency::USD)),
            );

        $job = new RecurringInvoiceJob(
            subscriptionRepository: $this->subscriptionRepo,
            invoiceGenerator: $invoiceGenerator,
            taxProvider: $this->taxProvider,
            logger: $this->logger,
        );

        $count = ($job)();

        self::assertSame(2, $count);
    }

    #[Test]
    public function invoiceMetadataContainsSubscriptionDetails(): void
    {
        $today = new DateTimeImmutable('today');
        $subscription = $this->createActiveSubscription($today, 9900, Currency::USD);

        $this->subscriptionRepo->method('findDueForRenewal')->willReturn([$subscription]);

        $this->taxProvider->method('calculateTax')->willReturn(
            new TaxCalculationResult(
                taxAmount: Money::of(0, Currency::USD),
                rateBasisPoints: 0,
                jurisdiction: 'US',
            ),
        );

        $invoiceGenerator = $this->createMock(InvoiceGeneratorInterface::class);
        $invoiceGenerator->expects(self::once())
            ->method('generate')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata) use ($subscription): bool {
                    return $metadata['subscription_id'] === $subscription->id
                        && $metadata['billing_cycle'] === 'monthly';
                }),
            )
            ->willReturn(
                Invoice::create('INV-001', 'cust', [], Money::of(0, Currency::USD)),
            );

        $job = new RecurringInvoiceJob(
            subscriptionRepository: $this->subscriptionRepo,
            invoiceGenerator: $invoiceGenerator,
            taxProvider: $this->taxProvider,
            logger: $this->logger,
        );

        ($job)();
    }

    private function createActiveSubscription(
        DateTimeImmutable $periodEnd,
        int $amountMinor,
        Currency $currency,
    ): Subscription {
        return new Subscription(
            id: 'sub-' . bin2hex(random_bytes(4)),
            customerId: 'cust-' . bin2hex(random_bytes(4)),
            planId: 'plan-basic',
            status: SubscriptionStatus::Active,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of($amountMinor, $currency),
            gateway: 'stripe',
            gatewaySubscriptionId: 'stripe_sub_123',
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: $periodEnd->modify('-30 days'),
            currentPeriodEnd: $periodEnd,
            trialEnd: null,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: $periodEnd->modify('-60 days'),
            updatedAt: $periodEnd,
        );
    }
}
