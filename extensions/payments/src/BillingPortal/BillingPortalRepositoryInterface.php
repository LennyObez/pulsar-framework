<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\BillingPortal;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\Subscription;

/**
 * Repository interface for billing portal data access.
 * @api
 */
#[Api(since: '1.0.0')]
interface BillingPortalRepositoryInterface
{
    /**
     * @return list<Subscription>
     */
    public function getActiveSubscriptions(string $customerId): array;

    public function getSubscription(string $subscriptionId): ?Subscription;

    /**
     * @return list<Invoice>
     */
    public function getRecentInvoices(string $customerId, int $limit = 10, int $offset = 0): array;

    /**
     * @return list<PaymentMethodSummary>
     */
    public function getPaymentMethods(string $customerId): array;
}
