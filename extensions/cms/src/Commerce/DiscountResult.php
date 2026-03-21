<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Result of applying a promotional discount to cart items.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiscountResult
{
    /**
     * @param int $totalDiscount Total discount amount in minor currency units
     * @param array<string, int> $itemDiscounts Map of product ID to discount amount in minor currency units
     */
    public function __construct(
        public int $totalDiscount,
        public array $itemDiscounts,
    ) {}
}
