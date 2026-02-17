<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\BillingPortal;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Contracts\SubscriptionManagerInterface;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;

/**
 * Self-service billing portal for customer subscription management.
 *
 * Provides a backend for customer-facing pages where users can view
 * invoices, update payment methods, change plans, and cancel subscriptions.
 */
#[Api(since: '1.0.0')]
final readonly class BillingPortalService
{
    public function __construct(
        private SubscriptionManagerInterface $subscriptionManager,
        private BillingPortalRepositoryInterface $repository,
    ) {}

    /**
     * Get billing summary for a customer.
     */
    public function getSummary(string $customerId): BillingPortalSummary
    {
        $subscriptions = $this->repository->getActiveSubscriptions($customerId);
        $invoices = $this->repository->getRecentInvoices($customerId, limit: 10);
        $paymentMethods = $this->repository->getPaymentMethods($customerId);

        $nextPaymentDate = null;
        $nextPaymentAmount = null;

        foreach ($subscriptions as $sub) {
            if ($sub->currentPeriodEnd !== null) {
                if ($nextPaymentDate === null || $sub->currentPeriodEnd < $nextPaymentDate) {
                    $nextPaymentDate = $sub->currentPeriodEnd;
                    $nextPaymentAmount = $sub->amount;
                }
            }
        }

        return new BillingPortalSummary(
            customerId: $customerId,
            subscriptions: $subscriptions,
            recentInvoices: $invoices,
            paymentMethods: $paymentMethods,
            nextPaymentDate: $nextPaymentDate,
            nextPaymentAmount: $nextPaymentAmount,
        );
    }

    /**
     * Cancel a subscription (at period end by default).
     *
     * @throws InvalidArgumentException If subscription not found or not owned by customer
     */
    public function cancelSubscription(string $customerId, string $subscriptionId): void
    {
        $sub = $this->repository->getSubscription($subscriptionId);

        if ($sub === null || $sub->customerId !== $customerId) {
            throw new InvalidArgumentException('Subscription not found');
        }

        $this->subscriptionManager->cancel($subscriptionId);
    }

    /**
     * Change subscription to a different plan.
     *
     * @throws InvalidArgumentException If subscription or plan not found
     */
    public function changePlan(string $customerId, string $subscriptionId, string $newPlanId, Money $newAmount): Subscription
    {
        $sub = $this->repository->getSubscription($subscriptionId);

        if ($sub === null || $sub->customerId !== $customerId) {
            throw new InvalidArgumentException('Subscription not found');
        }

        return $this->subscriptionManager->changePlan($subscriptionId, $newPlanId, $newAmount);
    }

    /**
     * Get invoice history for a customer.
     *
     * @return list<Invoice>
     */
    public function getInvoiceHistory(string $customerId, int $limit = 50, int $offset = 0): array
    {
        return $this->repository->getRecentInvoices($customerId, $limit, $offset);
    }
}
