<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tax;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Result of a tax calculation.
 */
#[Api(since: '1.0.0')]
final readonly class TaxCalculationResult
{
    /**
     * @param Money $taxAmount Computed tax amount
     * @param int $rateBasisPoints Tax rate in basis points (e.g., 2000 = 20%)
     * @param string $jurisdiction Human-readable jurisdiction name
     * @param bool $isExempt Whether the transaction is tax-exempt
     * @param list<TaxLineItem> $lineItems Breakdown by tax type (state, county, city, etc.)
     */
    public function __construct(
        public Money $taxAmount,
        public int $rateBasisPoints,
        public string $jurisdiction,
        public bool $isExempt = false,
        public array $lineItems = [],
    ) {}
}
