<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Tax calculation contract supporting per-item rates, VAT reverse charge, and multi-country rules.
 */
#[Api(since: '1.0.0')]
interface TaxCalculatorInterface
{
    /**
     * Calculate tax for a set of items.
     *
     * @param list<array{productId: string, amount: int, taxCategory: ?string, quantity: int}> $items
     * @param string $billingCountry ISO 3166-1 alpha-2 country code
     * @param string|null $vatNumber Customer's VAT number for reverse charge eligibility
     */
    public function calculate(array $items, string $billingCountry, ?string $vatNumber = null): TaxResult;
}
