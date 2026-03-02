<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

use function is_array;
use function is_int;
use function is_string;

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
            method: ShippingMethod::from(is_string($data['method'] ?? null) ? $data['method'] : 'standard'),
            baseAmount: is_int($data['base_amount'] ?? null) ? $data['base_amount'] : 0,
            perItemAmount: is_int($data['per_item_amount'] ?? null) ? $data['per_item_amount'] : 0,
            freeThreshold: isset($data['free_threshold']) ? (is_int($data['free_threshold']) ? $data['free_threshold'] : 0) : null,
            estimatedDays: isset($data['estimated_days']) ? (is_int($data['estimated_days']) ? $data['estimated_days'] : 0) : null,
            countryCodes: is_array($data['country_codes'] ?? null) ? array_values(array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $data['country_codes'])) : [],
        );
    }
}
