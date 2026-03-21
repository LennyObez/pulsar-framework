<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Result of a shipping calculation: cost, method, and estimated delivery.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ShippingResult
{
    /**
     * @param int $amount Shipping cost in minor currency units
     * @param ShippingMethod $method Selected shipping method
     * @param int|null $estimatedDays Estimated delivery time in business days
     */
    public function __construct(
        public int $amount,
        public ShippingMethod $method,
        public ?int $estimatedDays,
    ) {}
}
