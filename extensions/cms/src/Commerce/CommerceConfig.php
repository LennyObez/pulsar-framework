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
     * @param string $invoiceRenderer Renderer type for invoice generation
     * @param int $downloadTokenExpiryDays Days until download tokens expire
     * @param int $maxDownloads Default maximum downloads per digital purchase
     * @param bool $taxRequired Whether tax calculation is mandatory
     * @param string $currency Default ISO 4217 currency code
     */
    public function __construct(
        public array $taxRates = [],
        public string $invoiceRenderer = 'html',
        public int $downloadTokenExpiryDays = 30,
        public int $maxDownloads = 5,
        public bool $taxRequired = false,
        public string $currency = 'EUR',
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

        return new self(
            taxRates: $taxRates,
            invoiceRenderer: (string) ($data['invoiceRenderer'] ?? 'html'),
            downloadTokenExpiryDays: (int) ($data['downloadTokenExpiryDays'] ?? 30),
            maxDownloads: (int) ($data['maxDownloads'] ?? 5),
            taxRequired: (bool) ($data['taxRequired'] ?? false),
            currency: (string) ($data['currency'] ?? 'EUR'),
        );
    }
}
