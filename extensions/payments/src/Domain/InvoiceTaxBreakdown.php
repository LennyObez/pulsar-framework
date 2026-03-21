<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Per-line-item tax breakdown for e-invoicing compliance.
 *
 * Uses EN 16931 tax category codes:
 * - "S" = Standard rate
 * - "Z" = Zero-rated
 * - "E" = Exempt
 * - "AE" = Reverse charge
 * - "K" = Intra-community supply
 * - "G" = Export outside EU
 * - "O" = Not subject to VAT
 * - "L" = Canary Islands indirect tax (IGIC)
 * - "M" = Tax for production, services and importation in Ceuta/Melilla (IPSI)
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InvoiceTaxBreakdown
{
    /**
     * @param string $taxCategoryCode EN 16931 tax category code (e.g., "S", "Z", "E", "AE")
     * @param int $taxRatePercent Tax rate in basis points (10000 = 100%, e.g., 2100 = 21%)
     * @param Money $taxableAmount Amount subject to this tax rate
     * @param Money $taxAmount Computed tax amount
     * @param string $jurisdiction Tax jurisdiction identifier (e.g., "BE", "US-CA")
     */
    public function __construct(
        public string $taxCategoryCode,
        public int $taxRatePercent,
        public Money $taxableAmount,
        public Money $taxAmount,
        public string $jurisdiction,
    ) {}
}
