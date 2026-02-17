<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Extension\Payments\Internal\Billing\BillingEngine;
use Pulsar\Extension\Payments\Internal\Billing\DunningManager;
use Pulsar\Extension\Payments\Internal\Invoice\InvoiceGenerator;
use Pulsar\Extension\Payments\Internal\Invoice\InvoiceNumbering;
use Pulsar\Extension\Payments\Internal\Persistence\DbInvoiceRepository;
use Pulsar\Extension\Payments\Tax\TaxCalculationRequest;
use Pulsar\Extension\Payments\Tax\TaxCalculationResult;
use Pulsar\Extension\Payments\Tax\TaxProviderInterface;

final class BillingEngineTaxIntegrationTest extends TestCase
{
    private PaymentGatewayInterface&Stub $gateway;
    private SubscriptionRepositoryInterface&Stub $subscriptionRepository;
    private TaxProviderInterface&MockObject $taxProvider;

    protected function setUp(): void
    {
        $this->gateway = $this->createStub(PaymentGatewayInterface::class);
        $this->subscriptionRepository = $this->createStub(SubscriptionRepositoryInterface::class);
        $this->taxProvider = $this->createMock(TaxProviderInterface::class);

        // Gateway stubs
        $intent = new PaymentIntent(
            id: 'pi-1',
            amount: Money::of(2999, Currency::USD),
            status: PaymentIntentStatus::Created,
            provider: 'stripe',
            idempotencyKey: 'test-key',
            createdAt: new DateTimeImmutable(),
        );
        $this->gateway->method('createIntent')->willReturn($intent);
        $this->gateway->method('captureIntent')->willReturn(new Charge(
            id: 'ch-1',
            intentId: 'pi-1',
            amount: Money::of(2999, Currency::USD),
            status: ChargeStatus::Succeeded,
            provider: 'stripe',
            createdAt: new DateTimeImmutable(),
        ));
    }

    #[Test]
    public function processRenewalUsesTaxProviderInsteadOfStub(): void
    {
        $subscription = $this->buildSubscription(metadata: [
            'billing_address' => [
                'country' => 'DE',
                'region' => '',
                'postal_code' => '10115',
            ],
        ]);

        $taxAmount = Money::of(570, Currency::USD);

        $this->taxProvider->expects(self::once())
            ->method('calculateTax')
            ->with(self::callback(function (TaxCalculationRequest $request) use ($subscription): bool {
                return $request->amount->equals($subscription->amount)
                    && $request->countryCode === 'DE'
                    && $request->postalCode === '10115'
                    && $request->customerId === 'cust-billing';
            }))
            ->willReturn(new TaxCalculationResult(
                taxAmount: $taxAmount,
                rateBasisPoints: 1900,
                jurisdiction: 'DE',
            ));

        $engine = $this->buildEngine();
        $result = $engine->processRenewal($subscription);

        self::assertSame('cust-billing', $result->customerId);
    }

    #[Test]
    public function processRenewalExtractsUsRegionFromMetadata(): void
    {
        $subscription = $this->buildSubscription(metadata: [
            'billing_address' => [
                'country' => 'US',
                'region' => 'CA',
                'postal_code' => '90210',
            ],
        ]);

        $taxAmount = Money::of(217, Currency::USD);

        $this->taxProvider->expects(self::once())
            ->method('calculateTax')
            ->with(self::callback(function (TaxCalculationRequest $request): bool {
                return $request->countryCode === 'US'
                    && $request->regionCode === 'CA'
                    && $request->postalCode === '90210';
            }))
            ->willReturn(new TaxCalculationResult(
                taxAmount: $taxAmount,
                rateBasisPoints: 725,
                jurisdiction: 'US-CA',
            ));

        $engine = $this->buildEngine();
        $result = $engine->processRenewal($subscription);

        self::assertSame('cust-billing', $result->customerId);
    }

    #[Test]
    public function processRenewalHandlesMissingBillingAddress(): void
    {
        $subscription = $this->buildSubscription(metadata: []);

        $taxAmount = Money::zero(Currency::USD);

        $this->taxProvider->expects(self::once())
            ->method('calculateTax')
            ->with(self::callback(function (TaxCalculationRequest $request): bool {
                return $request->countryCode === ''
                    && $request->regionCode === ''
                    && $request->postalCode === '';
            }))
            ->willReturn(new TaxCalculationResult(
                taxAmount: $taxAmount,
                rateBasisPoints: 0,
                jurisdiction: '',
            ));

        $engine = $this->buildEngine();
        $result = $engine->processRenewal($subscription);

        self::assertSame('cust-billing', $result->customerId);
    }

    #[Test]
    public function processRenewalHandlesNonArrayBillingAddress(): void
    {
        $subscription = $this->buildSubscription(metadata: [
            'billing_address' => 'invalid-string',
        ]);

        $taxAmount = Money::zero(Currency::USD);

        $this->taxProvider->expects(self::once())
            ->method('calculateTax')
            ->with(self::callback(function (TaxCalculationRequest $request): bool {
                return $request->countryCode === ''
                    && $request->regionCode === ''
                    && $request->postalCode === '';
            }))
            ->willReturn(new TaxCalculationResult(
                taxAmount: $taxAmount,
                rateBasisPoints: 0,
                jurisdiction: '',
            ));

        $engine = $this->buildEngine();
        $result = $engine->processRenewal($subscription);

        self::assertSame('cust-billing', $result->customerId);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function buildSubscription(array $metadata = []): Subscription
    {
        return new Subscription(
            id: 'sub-billing',
            customerId: 'cust-billing',
            planId: 'plan-pro',
            status: SubscriptionStatus::Active,
            billingCycle: BillingCycle::Monthly,
            amount: Money::of(2999, Currency::USD),
            gateway: 'stripe',
            gatewaySubscriptionId: 'sub_stripe_1',
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
            metadata: $metadata,
        );
    }

    private function buildEngine(): BillingEngine
    {
        // Build real DunningManager (depends only on interfaces)
        $dunningManager = new DunningManager(
            $this->subscriptionRepository,
            new NullLogger(),
            PaymentsConfig::fromArray(['dunning_max_retries' => 4]),
        );

        // Build real InvoiceGenerator with stubbed dependencies
        $connection = $this->createStub(ConnectionInterface::class);
        $numbering = new InvoiceNumbering($connection);
        // InvoiceNumbering.next() calls connection, but we don't reach it
        // in the success path since the gateway is stubbed to succeed

        // Use a stub connection that returns a sequence number
        $connection->method('driver')->willReturn(\Pulsar\Database\Driver::PostgreSQL);
        $connection->method('query')->willReturn(
            new \Pulsar\Database\Result([new \Pulsar\Database\Row(['current_number' => 1])]),
        );

        $invoiceRepoConnection = $this->createStub(ConnectionInterface::class);
        $invoiceRepoConnection->method('execute')->willReturn(1);
        $invoiceRepository = new DbInvoiceRepository($invoiceRepoConnection);

        $invoiceGenerator = new InvoiceGenerator($numbering, $invoiceRepository);

        return new BillingEngine(
            $this->gateway,
            $this->subscriptionRepository,
            $invoiceGenerator,
            $dunningManager,
            $this->taxProvider,
            new NullLogger(),
            PaymentsConfig::fromArray(['dunning_max_retries' => 4]),
        );
    }
}
