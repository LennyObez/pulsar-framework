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
     * @param string $customerId Used to look up customer jurisdiction
     */
    public function calculate(Money $amount, string $customerId): Money
    {
        // Default implementation: apply standard VAT rate
        // In production, resolve rate from customer's billing address
        $rateBasisPoints = $this->resolveRate($customerId);

        return $amount->percentage($rateBasisPoints, RoundingMode::HalfUp);
    }

    /**
     * Resolve the applicable tax rate for a customer.
     *
     * @return int Tax rate in basis points (10000 = 100%)
     */
    private function resolveRate(string $customerId): int
    {
        // Placeholder: in production, look up customer address and apply
        // jurisdiction-specific rates. For now, return the default EU VAT rate.
        return self::DEFAULT_VAT_RATE_BASIS_POINTS;
    }
}
