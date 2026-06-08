<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Billing;

use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\RoundingMode;

/**
 * Tax calculation service.
 *
 * Computes applicable taxes based on the customer's jurisdiction.
 * In production, this should be extended with a tax provider
 * (e.g., Avalara, TaxJar) for accurate multi-jurisdiction tax rates.
 */
#[Internal]
final readonly class TaxCalculator
{
    /**
     * Default VAT rate in basis points (20% = 2000 basis points).
     */
    private const int DEFAULT_VAT_RATE_BASIS_POINTS = 2000;

    /**
     * Calculate tax for the given amount.
     *
     * The default implementation applies a flat VAT rate. A future
     * jurisdiction-aware implementation will need to introduce a
     * customer-id parameter and resolve the rate from the customer's
     * billing address.
     */
    public function calculate(Money $amount): Money
    {
        return $amount->percentage(self::DEFAULT_VAT_RATE_BASIS_POINTS, RoundingMode::HalfUp);
    }
}
