<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Pricing plan with trial, discount, and tier support.
 */
#[Api(since: '1.0.0')]
final readonly class PricingPlan
{
    /**
     * @param list<PricingTier> $tiers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public Money $price,
        public BillingCycle $billingCycle,
        public Currency $currency,
        public int $trialDays,
        public ?int $discountBasisPoints,
        public array $tiers,
        public bool $active,
        public array $metadata = [],
    ) {}

    /**
     * Calculate the effective price after any discount.
     */
    #[NoDiscard]
    public function effectivePrice(): Money
    {
        if ($this->discountBasisPoints === null || $this->discountBasisPoints === 0) {
            return $this->price;
        }

        $discount = $this->price->percentage($this->discountBasisPoints);

        return $this->price->subtract($discount);
    }

    /**
     * Whether this plan offers a free trial.
     */
    public function hasTrial(): bool
    {
        return $this->trialDays > 0;
    }
}
