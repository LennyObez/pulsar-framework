<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Configuration for the commerce subsystem.
 */
#[Api(since: '1.0.0')]
final readonly class CommerceConfig
{
    /**
     * @param list<TaxRateConfig> $taxRates Configured tax rate rules
     * @param list<ShippingRateConfig> $shippingRates Configured shipping rate rules
     * @param string $invoiceRenderer Renderer type for invoice generation
     * @param int $downloadTokenExpiryDays Days until download tokens expire
     * @param int $maxDownloads Default maximum downloads per digital purchase
     * @param bool $taxRequired Whether tax calculation is mandatory
     * @param string $currency Default ISO 4217 currency code
     * @param string $sellerCountry ISO 3166-1 alpha-2 seller country code for tax calculations
     * @param list<string> $euCountryCodes EU member state ISO 3166-1 alpha-2 codes for VAT reverse charge
     */
    public function __construct(
        public array $taxRates = [],
        public array $shippingRates = [],
        public string $invoiceRenderer = 'html',
        public int $downloadTokenExpiryDays = 30,
        public int $maxDownloads = 5,
        public bool $taxRequired = false,
        public string $currency = 'EUR',
        public string $sellerCountry = 'US',
        public array $euCountryCodes = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $taxRates = [];

        foreach ($data['taxRates'] ?? [] as $rate) {
            $taxRates[] = TaxRateConfig::fromArray($rate);
        }

        $shippingRates = [];

        foreach ($data['shippingRates'] ?? [] as $rate) {
            $shippingRates[] = ShippingRateConfig::fromArray($rate);
        }

        $defaultEuCodes = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        return new self(
            taxRates: $taxRates,
            shippingRates: $shippingRates,
            invoiceRenderer: (string) ($data['invoiceRenderer'] ?? 'html'),
            downloadTokenExpiryDays: (int) ($data['downloadTokenExpiryDays'] ?? 30),
            maxDownloads: (int) ($data['maxDownloads'] ?? 5),
            taxRequired: (bool) ($data['taxRequired'] ?? false),
            currency: (string) ($data['currency'] ?? 'EUR'),
            sellerCountry: (string) ($data['sellerCountry'] ?? 'US'),
            euCountryCodes: array_map(strval(...), $data['euCountryCodes'] ?? $defaultEuCodes),
        );
    }
}
