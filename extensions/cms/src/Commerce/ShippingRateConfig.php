<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Configuration for a single shipping rate rule.
 * @api
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
     * @param array{
     *     method?: string,
     *     base_amount?: int,
     *     per_item_amount?: int,
     *     free_threshold?: int|null,
     *     estimated_days?: int|null,
     *     country_codes?: list<string>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $freeThreshold = $data['free_threshold'] ?? null;
        $estimatedDays = $data['estimated_days'] ?? null;

        return new self(
            method: ShippingMethod::from(Coerce::string($data['method'] ?? null, 'standard')),
            baseAmount: Coerce::int($data['base_amount'] ?? null, 0),
            perItemAmount: Coerce::int($data['per_item_amount'] ?? null, 0),
            freeThreshold: $freeThreshold === null ? null : Coerce::int($freeThreshold, 0),
            estimatedDays: $estimatedDays === null ? null : Coerce::int($estimatedDays, 0),
            countryCodes: Coerce::listOfString($data['country_codes'] ?? null),
        );
    }
}
