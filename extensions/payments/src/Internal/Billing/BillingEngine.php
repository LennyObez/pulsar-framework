<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Billing;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Extension\Payments\Internal\Invoice\InvoiceGenerator;
use Pulsar\Extension\Payments\Tax\TaxCalculationRequest;
use Pulsar\Extension\Payments\Tax\TaxProviderInterface;
use Throwable;

use function bin2hex;
use function is_array;
use function is_string;
use function random_bytes;

/**
 * Recurring billing engine.
 *
 * Processes subscription renewals by creating invoices and charging
 * the customer's payment method. Integrates with DunningManager
 * for failed payment recovery.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class BillingEngine
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private InvoiceGenerator $invoiceGenerator,
        private DunningManager $dunningManager,
        private TaxProviderInterface $taxProvider,
        private LoggerInterface $logger,
        private PaymentsConfig $config,
    ) {}

    /**
     * Process a subscription renewal.
     */
    public function processRenewal(Subscription $subscription): Invoice
    {
        $billingAddress = $this->resolveBillingAddress($subscription);

        $taxRequest = new TaxCalculationRequest(
            amount: $subscription->amount,
            countryCode: $billingAddress['country'] ?? '',
            regionCode: $billingAddress['region'] ?? '',
            postalCode: $billingAddress['postal_code'] ?? '',
            customerId: $subscription->customerId,
        );

        $taxResult = $this->taxProvider->calculateTax($taxRequest);
        $tax = $taxResult->taxAmount;

        $invoice = $this->invoiceGenerator->generate(
            customerId: $subscription->customerId,
            subscriptionId: $subscription->id,
            lineItems: [
                InvoiceLineItem::create(
                    description: "Subscription renewal: {$subscription->planId}",
                    quantity: 1,
                    unitPrice: $subscription->amount,
                ),
            ],
            tax: $tax,
        );

        $idempotencyKey = 'renewal_' . $subscription->id . '_' . bin2hex(random_bytes(8));

        try {
            $intent = $this->gateway->createIntent($invoice->total, $idempotencyKey, [
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice->id,
            ]);

            $this->gateway->captureIntent($intent->id, $idempotencyKey . '_capture');

            $nextPeriodEnd = $subscription->billingCycle->nextDate($subscription->currentPeriodEnd ?? $subscription->createdAt);

            $renewedSubscription = $subscription->withStatus(
                SubscriptionStatus::Active,
                currentPeriodEnd: $nextPeriodEnd,
            );

            $this->subscriptionRepository->save($renewedSubscription);

            $this->logger->info('Subscription renewed', [
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice->id,
                'next_period_end' => $nextPeriodEnd->format('c'),
            ]);

            return $invoice->markPaid();
        } catch (Throwable $e) {
            $this->logger->warning('Subscription renewal failed, entering dunning', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dunningManager->enterDunning($subscription);

            $this->logger->info('Dunning configured with max retries', [
                'max_retries' => $this->config->dunningMaxRetries,
            ]);

            return $invoice;
        }
    }

    /**
     * Resolve the billing address from subscription metadata.
     *
     * @return array{country: string, region: string, postal_code: string}
     */
    private function resolveBillingAddress(Subscription $subscription): array
    {
        /** @var array<string, mixed> $address */
        $address = is_array($subscription->metadata['billing_address'] ?? null)
            ? $subscription->metadata['billing_address']
            : [];

        /** @var mixed $rawCountry */
        $rawCountry = $address['country'] ?? null;
        /** @var mixed $rawRegion */
        $rawRegion = $address['region'] ?? null;
        /** @var mixed $rawPostal */
        $rawPostal = $address['postal_code'] ?? null;

        return [
            'country' => is_string($rawCountry) ? $rawCountry : '',
            'region' => is_string($rawRegion) ? $rawRegion : '',
            'postal_code' => is_string($rawPostal) ? $rawPostal : '',
        ];
    }
}
