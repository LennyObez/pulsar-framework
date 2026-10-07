<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Seller or buyer party information for e-invoicing.
 *
 * Contains business identity, address, banking, and electronic addressing
 * details required by EN 16931 and Peppol BIS 3.0.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InvoiceParty
{
    /**
     * @param string $name Legal name of the party
     * @param string|null $vatNumber VAT identification number (e.g., "BE0123456789")
     * @param string|null $registrationNumber National business registration number
     * @param string|null $legalForm Legal form (e.g., "Ltd", "GmbH", "SA", "BV")
     * @param string $addressLine1 Street address line 1
     * @param string|null $addressLine2 Street address line 2
     * @param string $city City name
     * @param string $postalCode Postal or ZIP code
     * @param string $country ISO 3166-1 alpha-2 country code
     * @param string|null $iban International Bank Account Number
     * @param string|null $bic Bank Identifier Code (SWIFT)
     * @param string $email Contact email address
     * @param string|null $phone Contact phone number
     * @param string|null $gln Global Location Number for Peppol endpoint identification
     */
    public function __construct(
        public string $name,
        public ?string $vatNumber,
        public ?string $registrationNumber,
        public ?string $legalForm,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $postalCode,
        public string $country,
        public ?string $iban,
        public ?string $bic,
        public string $email,
        public ?string $phone,
        public ?string $gln,
    ) {}
}
