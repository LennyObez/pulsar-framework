<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

use DateTimeImmutable;

/**
 * Subscription entity.
 *
 * Represents a tenant's subscription to a billing plan.
 */
final class Subscription
{
    /**
     * @param non-empty-string        $id               Unique subscription identifier
     * @param non-empty-string        $tenantId         Associated tenant identifier
     * @param non-empty-string        $planId           Associated plan identifier
     * @param SubscriptionStatus      $status           Current subscription status
     * @param non-empty-string        $billingCycle     Billing cycle (e.g., "monthly", "annual")
     * @param DateTimeImmutable      $startedAt        Subscription start date
     * @param DateTimeImmutable|null $currentPeriodEnd Current billing period end
     * @param DateTimeImmutable|null $cancelledAt      Cancellation date
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $planId,
        public SubscriptionStatus $status = SubscriptionStatus::Active,
        public readonly string $billingCycle = 'monthly',
        public readonly DateTimeImmutable $startedAt = new DateTimeImmutable(),
        public readonly ?DateTimeImmutable $currentPeriodEnd = null,
        public readonly ?DateTimeImmutable $cancelledAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled;
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === SubscriptionStatus::GracePeriod;
    }

    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }
}
