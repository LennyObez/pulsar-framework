<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;

/**
 * Subscription lifecycle management contract.
 * @api
 */
#[Api(since: '1.0.0')]
interface SubscriptionManagerInterface
{
    /**
     * Create a new subscription.
     *
     * @param array<string, mixed> $metadata
     */
    public function create(
        string $customerId,
        string $planId,
        BillingCycle $billingCycle,
        Money $amount,
        string $gateway,
        ?int $trialDays = null,
        array $metadata = [],
    ): Subscription;

    /**
     * Cancel a subscription (access continues until period end).
     */
    public function cancel(string $subscriptionId): Subscription;

    /**
     * Pause a subscription (no billing, no access).
     */
    public function pause(string $subscriptionId): Subscription;

    /**
     * Resume a paused subscription.
     */
    public function resume(string $subscriptionId): Subscription;

    /**
     * Change the plan for an existing subscription.
     */
    public function changePlan(string $subscriptionId, string $newPlanId, Money $newAmount): Subscription;

    /**
     * Retrieve a subscription by ID.
     */
    public function get(string $subscriptionId): ?Subscription;

    /**
     * Retrieve active subscriptions for a customer.
     *
     * @return list<Subscription>
     */
    public function getByCustomer(string $customerId): array;

    /**
     * Update subscription status (used by webhook handlers).
     */
    public function updateStatus(string $subscriptionId, SubscriptionStatus $status): Subscription;
}
