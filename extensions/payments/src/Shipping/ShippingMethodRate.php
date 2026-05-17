<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Shipping;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * A shipping rate for a specific method within a zone.
 *
 * Rates can be calculated as flat amounts, per-weight, per-price-tier,
 * per-item, or free-above-threshold. The {@see type} field determines
 * which calculation strategy applies.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ShippingMethodRate
{
    /**
     * @param non-empty-string $id Unique rate identifier
     * @param non-empty-string $methodId Human-readable method key (e.g., 'standard', 'express')
     * @param non-empty-string $label Display label for the shipping option
     * @param ShippingRateType $type Calculation strategy
     * @param Money $baseRate Base shipping charge
     * @param int|null $freeAboveAmount Subtotal threshold (minor units) above which shipping is free
     * @param int|null $perItemAmount Additional per-item charge (minor units)
     * @param int|null $minWeightGrams Minimum weight for weight-based tiers (grams)
     * @param int|null $maxWeightGrams Maximum weight for weight-based tiers (grams)
     * @param int|null $estimatedDaysMin Minimum estimated delivery days
     * @param int|null $estimatedDaysMax Maximum estimated delivery days
     * @param bool $enabled Whether this rate is currently active
     */
    public function __construct(
        public string $id,
        public string $methodId,
        public string $label,
        public ShippingRateType $type,
        public Money $baseRate,
        public ?int $freeAboveAmount = null,
        public ?int $perItemAmount = null,
        public ?int $minWeightGrams = null,
        public ?int $maxWeightGrams = null,
        public ?int $estimatedDaysMin = null,
        public ?int $estimatedDaysMax = null,
        public bool $enabled = true,
    ) {}

    /**
     * Whether this rate qualifies for free shipping at the given subtotal.
     *
     * @param int $subtotalMinorUnits Cart subtotal in minor currency units
     */
    public function isFreeAt(int $subtotalMinorUnits): bool
    {
        return $this->type === ShippingRateType::FreeAbove
            && $this->freeAboveAmount !== null
            && $subtotalMinorUnits >= $this->freeAboveAmount;
    }

    /**
     * Format the estimated delivery time as a human-readable string.
     */
    public function estimatedDelivery(): ?string
    {
        if ($this->estimatedDaysMin === null && $this->estimatedDaysMax === null) {
            return null;
        }

        if ($this->estimatedDaysMin !== null && $this->estimatedDaysMax !== null) {
            if ($this->estimatedDaysMin === $this->estimatedDaysMax) {
                return "{$this->estimatedDaysMin} business days";
            }

            return "{$this->estimatedDaysMin}-{$this->estimatedDaysMax} business days";
        }

        $days = $this->estimatedDaysMin ?? $this->estimatedDaysMax;

        return "{$days} business days";
    }
}
