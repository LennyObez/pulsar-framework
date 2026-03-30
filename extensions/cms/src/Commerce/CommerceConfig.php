<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

use function array_values;

/**
 * Configuration for the commerce subsystem.
 * @api
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
     * @param array{
     *     taxRates?: list<array<string, mixed>>,
     *     shippingRates?: list<array<string, mixed>>,
     *     invoiceRenderer?: string,
     *     downloadTokenExpiryDays?: int,
     *     maxDownloads?: int,
     *     taxRequired?: bool|int|string,
     *     currency?: string,
     *     sellerCountry?: string,
     *     euCountryCodes?: list<string>,
     * } $data
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
            invoiceRenderer: $data['invoiceRenderer'] ?? 'html',
            downloadTokenExpiryDays: $data['downloadTokenExpiryDays'] ?? 30,
            maxDownloads: $data['maxDownloads'] ?? 5,
            taxRequired: (bool) ($data['taxRequired'] ?? false),
            currency: $data['currency'] ?? 'EUR',
            sellerCountry: $data['sellerCountry'] ?? 'US',
            euCountryCodes: array_values($data['euCountryCodes'] ?? $defaultEuCodes),
        );
    }
}
