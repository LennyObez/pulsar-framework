<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

use function is_array;
use function is_string;

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

        $rawTaxRates = is_array($data['taxRates'] ?? null) ? $data['taxRates'] : [];

        foreach ($rawTaxRates as $rate) {
            if (is_array($rate)) {
                /** @var array<string, mixed> $rate */
                $taxRates[] = TaxRateConfig::fromArray($rate);
            }
        }

        $shippingRates = [];
        $rawShippingRates = is_array($data['shippingRates'] ?? null) ? $data['shippingRates'] : [];

        foreach ($rawShippingRates as $rate) {
            if (is_array($rate)) {
                /** @var array<string, mixed> $rate */
                $shippingRates[] = ShippingRateConfig::fromArray($rate);
            }
        }

        $defaultEuCodes = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        return new self(
            taxRates: $taxRates,
            shippingRates: $shippingRates,
            invoiceRenderer: isset($data['invoiceRenderer']) ? (is_string($data['invoiceRenderer'] ?? null) ? $data['invoiceRenderer'] : '') : 'html',
            downloadTokenExpiryDays: is_numeric($data['downloadTokenExpiryDays'] ?? null) ? (int) $data['downloadTokenExpiryDays'] : 30,
            maxDownloads: is_numeric($data['maxDownloads'] ?? null) ? (int) $data['maxDownloads'] : 5,
            taxRequired: (bool) ($data['taxRequired'] ?? false),
            currency: isset($data['currency']) ? (is_string($data['currency'] ?? null) ? $data['currency'] : '') : 'EUR',
            sellerCountry: isset($data['sellerCountry']) ? (is_string($data['sellerCountry'] ?? null) ? $data['sellerCountry'] : '') : 'US',
            euCountryCodes: array_values(array_map(
                static fn(mixed $v): string => is_string($v) ? $v : '',
                is_array($data['euCountryCodes'] ?? null) ? $data['euCountryCodes'] : $defaultEuCodes,
            )),
        );
    }
}
