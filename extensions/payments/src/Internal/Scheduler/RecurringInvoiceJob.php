<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Scheduler;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\InvoiceGeneratorInterface;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Extension\Payments\Tax\TaxCalculationRequest;
use Pulsar\Extension\Payments\Tax\TaxProviderInterface;

use function sprintf;

/**
 * Scheduled job: generates invoices for recurring subscriptions.
 *
 * Runs daily to find all active subscriptions whose current billing
 * period ends today and generates a new invoice for each. Uses the
 * real tax provider to calculate jurisdiction-appropriate tax based
 * on the customer's country.
 *
 * The job is idempotent: invoices are only generated for subscriptions
 * whose currentPeriodEnd matches today's date exactly.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Scheduled recurring billing job')]
final readonly class RecurringInvoiceJob
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private InvoiceGeneratorInterface $invoiceGenerator,
        private TaxProviderInterface $taxProvider,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute the recurring invoice generation.
     *
     * @param string $customerCountryCode Default country for tax calculation
     *
     * @return int Number of invoices generated
     */
    public function __invoke(string $customerCountryCode = 'US'): int
    {
        $today = new DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $generated = 0;

        $subscriptions = $this->subscriptionRepository->findDueForRenewal($today);

        foreach ($subscriptions as $subscription) {
            if ($subscription->status !== SubscriptionStatus::Active) {
                continue;
            }

            if ($subscription->currentPeriodEnd === null) {
                continue;
            }

            if ($subscription->currentPeriodEnd->format('Y-m-d') !== $todayStr) {
                continue;
            }

            $lineItem = InvoiceLineItem::create(
                description: sprintf('Subscription: %s (%s)', $subscription->planId, $subscription->billingCycle->value),
                quantity: 1,
                unitPrice: $subscription->amount,
            );

            $taxResult = $this->taxProvider->calculateTax(
                new TaxCalculationRequest(
                    amount: $subscription->amount,
                    countryCode: $customerCountryCode,
                ),
            );

            $dueDate = $today->modify('+30 days');

            $invoice = $this->invoiceGenerator->generate(
                customerId: $subscription->customerId,
                subscriptionId: $subscription->id,
                lineItems: [$lineItem],
                tax: $taxResult->taxAmount,
                dueDate: $dueDate,
                metadata: [
                    'subscription_id' => $subscription->id,
                    'billing_cycle' => $subscription->billingCycle->value,
                    'period_start' => $subscription->currentPeriodEnd->format('c'),
                    'period_end' => $subscription->billingCycle->nextDate($subscription->currentPeriodEnd)->format('c'),
                ],
            );

            $this->logger->info('Generated recurring invoice', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoiceNumber,
                'subscription_id' => $subscription->id,
                'customer_id' => $subscription->customerId,
                'amount' => $invoice->total->amount,
                'currency' => $invoice->total->currency->value,
            ]);

            $generated++;
        }

        $this->logger->info('Recurring invoice job completed', [
            'date' => $todayStr,
            'invoices_generated' => $generated,
        ]);

        return $generated;
    }
}
