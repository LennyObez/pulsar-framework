<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Typed configuration DTO for business/company profile.
 *
 * Serves as the single source of truth for company information across the framework.
 * Extensions (CMS commerce, Payments invoicing, Compliance, Forum, Analytics) consume
 * this DTO instead of maintaining their own scattered copies of company data.
 *
 * Maps from `config/business.php` with environment variable overrides.
 */
#[Api(since: '1.0.0')]
final readonly class BusinessProfileConfig
{
    /**
     * @param string $companyName Legal company name
     * @param string|null $tradingName "Doing business as" name (if different from legal name)
     * @param string|null $legalForm Legal form (Ltd, GmbH, SARL, BV, LLC, etc.)
     * @param string|null $registrationNumber Company registration number
     * @param string|null $vatNumber EU VAT identification number (e.g., BE0123456789)
     * @param string|null $taxId Non-EU tax ID (EIN, TFN, SIREN, etc.)
     * @param string|null $addressLine1 Street address line 1
     * @param string|null $addressLine2 Street address line 2 (suite, unit, etc.)
     * @param string|null $city City or locality
     * @param string|null $postalCode ZIP or postal code
     * @param string|null $region State, province, or region
     * @param string $country ISO 3166-1 alpha-2 country code
     * @param string|null $phone Phone number (E.164 recommended)
     * @param string|null $email Contact email address
     * @param string|null $website Company website URL
     * @param string|null $iban International Bank Account Number (for invoicing)
     * @param string|null $bic Bank Identifier Code / SWIFT code
     * @param string|null $bankName Bank name (for invoice display)
     * @param string|null $logoPath Relative path to the company logo file
     * @param string|null $peppolId Peppol endpoint identifier (GLN or other)
     * @param string|null $peppolScheme Peppol identifier scheme (e.g., "0088" for GLN)
     */
    public function __construct(
        public string $companyName = '',
        public ?string $tradingName = null,
        public ?string $legalForm = null,
        public ?string $registrationNumber = null,
        public ?string $vatNumber = null,
        public ?string $taxId = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?string $region = null,
        public string $country = 'US',
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $website = null,
        public ?string $iban = null,
        public ?string $bic = null,
        public ?string $bankName = null,
        public ?string $logoPath = null,
        public ?string $peppolId = null,
        public ?string $peppolScheme = null,
    ) {}

    /**
     * Build from a raw config array with environment variable overrides.
     *
     * @param array<string, mixed> $data Raw array from config/business.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        return new self(
            companyName: self::resolveString($data, 'company_name', $environment, 'BUSINESS_NAME', ''),
            tradingName: self::resolveNullableString($data, 'trading_name', $environment, 'BUSINESS_TRADING_NAME'),
            legalForm: self::resolveNullableString($data, 'legal_form', $environment, 'BUSINESS_LEGAL_FORM'),
            registrationNumber: self::resolveNullableString($data, 'registration_number', $environment, 'BUSINESS_REGISTRATION_NUMBER'),
            vatNumber: self::resolveNullableString($data, 'vat_number', $environment, 'BUSINESS_VAT_NUMBER'),
            taxId: self::resolveNullableString($data, 'tax_id', $environment, 'BUSINESS_TAX_ID'),
            addressLine1: self::resolveNullableString($data, 'address_line1', $environment, 'BUSINESS_ADDRESS_LINE1'),
            addressLine2: self::resolveNullableString($data, 'address_line2', $environment, 'BUSINESS_ADDRESS_LINE2'),
            city: self::resolveNullableString($data, 'city', $environment, 'BUSINESS_CITY'),
            postalCode: self::resolveNullableString($data, 'postal_code', $environment, 'BUSINESS_POSTAL_CODE'),
            region: self::resolveNullableString($data, 'region', $environment, 'BUSINESS_REGION'),
            country: self::resolveString($data, 'country', $environment, 'BUSINESS_COUNTRY', 'US'),
            phone: self::resolveNullableString($data, 'phone', $environment, 'BUSINESS_PHONE'),
            email: self::resolveNullableString($data, 'email', $environment, 'BUSINESS_EMAIL'),
            website: self::resolveNullableString($data, 'website', $environment, 'BUSINESS_WEBSITE'),
            iban: self::resolveNullableString($data, 'iban', $environment, 'BUSINESS_IBAN'),
            bic: self::resolveNullableString($data, 'bic', $environment, 'BUSINESS_BIC'),
            bankName: self::resolveNullableString($data, 'bank_name', $environment, 'BUSINESS_BANK_NAME'),
            logoPath: self::resolveNullableString($data, 'logo_path', $environment, 'BUSINESS_LOGO_PATH'),
            peppolId: self::resolveNullableString($data, 'peppol_id', $environment, 'BUSINESS_PEPPOL_ID'),
            peppolScheme: self::resolveNullableString($data, 'peppol_scheme', $environment, 'BUSINESS_PEPPOL_SCHEME'),
        );
    }

    /**
     * Get the display name: trading name if set, otherwise company name.
     */
    #[NoDiscard]
    public function displayName(): string
    {
        return $this->tradingName ?? $this->companyName;
    }

    /**
     * Get a formatted single-line address string.
     */
    #[NoDiscard]
    public function formattedAddress(): string
    {
        $parts = array_filter([
            $this->addressLine1,
            $this->addressLine2,
            implode(' ', array_filter([$this->city, $this->region, $this->postalCode])),
            $this->country,
        ], static fn(?string $part): bool => $part !== null && $part !== '');

        return implode(', ', $parts);
    }

    /**
     * Get seller party data formatted for invoice generation.
     *
     * @return array{
     *     name: string,
     *     address: array{line1: string|null, line2: string|null, city: string|null, postal_code: string|null, region: string|null, country: string},
     *     vat_number: string|null,
     *     tax_id: string|null,
     *     registration_number: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     iban: string|null,
     *     bic: string|null,
     *     bank_name: string|null,
     *     peppol_id: string|null,
     *     peppol_scheme: string|null,
     * }
     */
    #[NoDiscard]
    public function sellerParty(): array
    {
        return [
            'name' => $this->displayName(),
            'address' => [
                'line1' => $this->addressLine1,
                'line2' => $this->addressLine2,
                'city' => $this->city,
                'postal_code' => $this->postalCode,
                'region' => $this->region,
                'country' => $this->country,
            ],
            'vat_number' => $this->vatNumber,
            'tax_id' => $this->taxId,
            'registration_number' => $this->registrationNumber,
            'email' => $this->email,
            'phone' => $this->phone,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'bank_name' => $this->bankName,
            'peppol_id' => $this->peppolId,
            'peppol_scheme' => $this->peppolScheme,
        ];
    }

    /**
     * Check whether the profile has sufficient data for invoice generation.
     */
    #[NoDiscard]
    public function isCompleteForInvoicing(): bool
    {
        return $this->companyName !== ''
            && $this->addressLine1 !== null
            && $this->city !== null
            && $this->country !== '';
    }

    /**
     * Resolve a required string field with env override and fallback default.
     *
     * @param array<string, mixed> $data
     */
    private static function resolveString(
        array $data,
        string $key,
        Environment $environment,
        string $envKey,
        string $default,
    ): string {
        $envValue = $environment->get($envKey);

        if ($envValue !== null) {
            return $envValue;
        }

        $raw = $data[$key] ?? $default;

        return is_string($raw) ? $raw : $default;
    }

    /**
     * Resolve a nullable string field with env override.
     *
     * @param array<string, mixed> $data
     */
    private static function resolveNullableString(
        array $data,
        string $key,
        Environment $environment,
        string $envKey,
    ): ?string {
        $envValue = $environment->get($envKey);

        if ($envValue !== null) {
            return $envValue;
        }

        $raw = $data[$key] ?? null;

        return is_string($raw) ? $raw : null;
    }
}
