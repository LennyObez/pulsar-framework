<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable seller/company profile for invoice rendering.
 *
 * Aggregates all seller information required by EU invoicing
 * regulations (VAT Directive 2006/112/EC, Article 226).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SellerProfile
{
    /**
     * @param string $companyName   Legal company name
     * @param string $legalForm     Legal form (e.g., "SARL", "GmbH", "Ltd")
     * @param string $vatNumber     EU VAT identification number
     * @param string $registrationNumber Company registration number
     * @param string $addressLine1  Primary address line
     * @param string $addressLine2  Secondary address line
     * @param string $city          City
     * @param string $postalCode    Postal/ZIP code
     * @param string $country       Country name or ISO code
     * @param string $iban          IBAN for bank transfers
     * @param string $bic           BIC/SWIFT code
     * @param string $bankName      Name of the bank
     * @param string $phone         Contact phone number
     * @param string $email         Contact email address
     * @param string $website       Company website URL
     * @param ?string $logoPath     Path to the company logo file
     */
    public function __construct(
        public string $companyName,
        public string $legalForm = '',
        public string $vatNumber = '',
        public string $registrationNumber = '',
        public string $addressLine1 = '',
        public string $addressLine2 = '',
        public string $city = '',
        public string $postalCode = '',
        public string $country = '',
        public string $iban = '',
        public string $bic = '',
        public string $bankName = '',
        public string $phone = '',
        public string $email = '',
        public string $website = '',
        public ?string $logoPath = null,
    ) {}

    /**
     * Build from a key-value array (e.g., from CMS settings).
     *
     * @param array{
     *     company_name?: string,
     *     legal_form?: string,
     *     vat_number?: string,
     *     registration_number?: string,
     *     address_line1?: string,
     *     address_line2?: string,
     *     city?: string,
     *     postal_code?: string,
     *     country?: string,
     *     iban?: string,
     *     bic?: string,
     *     bank_name?: string,
     *     phone?: string,
     *     email?: string,
     *     website?: string,
     *     logo_path?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $logoPath = $data['logo_path'] ?? null;

        return new self(
            companyName: $data['company_name'] ?? '',
            legalForm: $data['legal_form'] ?? '',
            vatNumber: $data['vat_number'] ?? '',
            registrationNumber: $data['registration_number'] ?? '',
            addressLine1: $data['address_line1'] ?? '',
            addressLine2: $data['address_line2'] ?? '',
            city: $data['city'] ?? '',
            postalCode: $data['postal_code'] ?? '',
            country: $data['country'] ?? '',
            iban: $data['iban'] ?? '',
            bic: $data['bic'] ?? '',
            bankName: $data['bank_name'] ?? '',
            phone: $data['phone'] ?? '',
            email: $data['email'] ?? '',
            website: $data['website'] ?? '',
            logoPath: ($logoPath !== null && $logoPath !== '') ? $logoPath : null,
        );
    }

    /**
     * Format the full postal address as a multi-line string.
     */
    public function formattedAddress(): string
    {
        $lines = [];

        if ($this->addressLine1 !== '') {
            $lines[] = $this->addressLine1;
        }

        if ($this->addressLine2 !== '') {
            $lines[] = $this->addressLine2;
        }

        $cityLine = '';

        if ($this->postalCode !== '') {
            $cityLine .= $this->postalCode;
        }

        if ($this->city !== '') {
            $cityLine .= ($cityLine !== '' ? ' ' : '') . $this->city;
        }

        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }

        if ($this->country !== '') {
            $lines[] = $this->country;
        }

        return implode("\n", $lines);
    }

    /**
     * Whether the profile has valid bank details for payment.
     */
    public function hasBankDetails(): bool
    {
        return $this->iban !== '' && $this->bic !== '';
    }

}
