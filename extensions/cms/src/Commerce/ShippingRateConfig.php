<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Configuration for a single shipping rate rule.
 */
#[Api(since: '1.0.0')]
final readonly class ShippingRateConfig
{
    /**
     * @param ShippingMethod $method Shipping method this rate applies to
     * @param int $baseAmount Base shipping cost in minor currency units
     * @param int $perItemAmount Additional cost per item in minor currency units
     * @param int|null $freeThreshold Subtotal threshold (minor units) above which shipping is free
     * @param int|null $estimatedDays Estimated delivery time in business days
     * @param list<string> $countryCodes ISO 3166-1 alpha-2 country codes; empty = all countries
     */
    public function __construct(
        public ShippingMethod $method,
        public int $baseAmount,
        public int $perItemAmount = 0,
        public ?int $freeThreshold = null,
        public ?int $estimatedDays = null,
        public array $countryCodes = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            method: ShippingMethod::from((string) ($data['method'] ?? 'standard')),
            baseAmount: (int) ($data['base_amount'] ?? 0),
            perItemAmount: (int) ($data['per_item_amount'] ?? 0),
            freeThreshold: isset($data['free_threshold']) ? (int) $data['free_threshold'] : null,
            estimatedDays: isset($data['estimated_days']) ? (int) $data['estimated_days'] : null,
            countryCodes: (array) ($data['country_codes'] ?? []),
        );
    }
}
