<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Tax calculation for a single line item.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TaxLineItem
{
    /**
     * @param string $productId Product this tax applies to
     * @param float $taxRate Applied tax rate as a decimal
     * @param int $taxAmount Tax amount in minor currency units
     */
    public function __construct(
        public string $productId,
        public float $taxRate,
        public int $taxAmount,
    ) {}
}
