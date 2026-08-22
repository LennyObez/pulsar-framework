<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tax;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Request DTO for tax calculation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TaxCalculationRequest
{
    /**
     * @param Money $amount Taxable amount
     * @param string $countryCode ISO 3166-1 alpha-2 country code
     * @param string $regionCode State/province code (e.g., 'CA' for California)
     * @param string $postalCode Postal/ZIP code
     * @param string $productType Product category for tax classification
     * @param string $customerId Customer identifier for exemption lookup
     * @param bool $isDigital Whether this is a digital product/service
     */
    public function __construct(
        public Money $amount,
        public string $countryCode,
        public string $regionCode = '',
        public string $postalCode = '',
        public string $productType = 'general',
        public string $customerId = '',
        public bool $isDigital = false,
    ) {}
}
