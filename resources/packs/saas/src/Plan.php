<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Billing plan entity.
 *
 * Represents a subscription plan with pricing and feature limits.
 */
final class Plan
{
    /**
     * @param non-empty-string  $id              Unique plan identifier
     * @param non-empty-string  $name            Plan display name
     * @param non-empty-string  $slug            URL-safe plan slug
     * @param int               $monthlyPriceCents Monthly price in minor currency units
     * @param int               $annualPriceCents  Annual price in minor currency units
     * @param non-empty-string  $currency        ISO 4217 currency code
     * @param int               $maxUsers        Maximum users per tenant
     * @param int               $maxStorageMb    Maximum storage in megabytes
     * @param list<string>      $features        List of feature slugs included
     * @param bool              $isPublic        Whether this plan is publicly available
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly int $monthlyPriceCents,
        public readonly int $annualPriceCents,
        public readonly string $currency = 'USD',
        public readonly int $maxUsers = 5,
        public readonly int $maxStorageMb = 1024,
        public readonly array $features = [],
        public readonly bool $isPublic = true,
    ) {}

    public function monthlyPriceFormatted(): string
    {
        return number_format($this->monthlyPriceCents / 100, 2, '.', ',');
    }

    public function annualPriceFormatted(): string
    {
        return number_format($this->annualPriceCents / 100, 2, '.', ',');
    }

    public function annualSavingsPercent(): float
    {
        if ($this->monthlyPriceCents === 0) {
            return 0.0;
        }

        $annualFromMonthly = $this->monthlyPriceCents * 12;

        return round((1 - ($this->annualPriceCents / $annualFromMonthly)) * 100, 1);
    }

    public function hasFeature(string $featureSlug): bool
    {
        return in_array($featureSlug, $this->features, true);
    }
}
