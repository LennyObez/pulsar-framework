<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tax;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * A single line item in a tax breakdown.
 */
#[Api(since: '1.0.0')]
final readonly class TaxLineItem
{
    /**
     * @param string $name Tax name (e.g., 'State Sales Tax', 'VAT', 'GST')
     * @param int $rateBasisPoints Rate in basis points
     * @param Money $amount Tax amount for this line
     * @param string $jurisdiction Jurisdiction identifier
     */
    public function __construct(
        public string $name,
        public int $rateBasisPoints,
        public Money $amount,
        public string $jurisdiction = '',
    ) {}
}
