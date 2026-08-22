<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Tax calculation result with per-item breakdown.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TaxResult
{
    /**
     * @param list<TaxLineItem> $items Per-item tax breakdown
     * @param int $totalTax Total tax amount in minor currency units
     * @param bool $reverseCharge Whether EU VAT reverse charge applies
     */
    public function __construct(
        public array $items,
        public int $totalTax,
        public bool $reverseCharge,
    ) {}
}
