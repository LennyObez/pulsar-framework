<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\BillingPortal;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;

/**
 * Summary data for the customer billing portal page.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BillingPortalSummary
{
    /**
     * @param string $customerId Customer identifier
     * @param list<Subscription> $subscriptions Active subscriptions
     * @param list<Invoice> $recentInvoices Recent invoices
     * @param list<PaymentMethodSummary> $paymentMethods Stored payment methods
     * @param DateTimeImmutable|null $nextPaymentDate Next upcoming payment date
     * @param Money|null $nextPaymentAmount Next payment amount
     */
    public function __construct(
        public string $customerId,
        public array $subscriptions,
        public array $recentInvoices,
        public array $paymentMethods,
        public ?DateTimeImmutable $nextPaymentDate = null,
        public ?Money $nextPaymentAmount = null,
    ) {}

    public function hasActiveSubscriptions(): bool
    {
        return $this->subscriptions !== [];
    }
}
