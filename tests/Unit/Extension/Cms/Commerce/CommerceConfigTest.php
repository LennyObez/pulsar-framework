<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Commerce\ShippingRateConfig;
use Pulsar\Extension\Cms\Commerce\TaxRateConfig;

#[CoversClass(CommerceConfig::class)]
#[CoversClass(TaxRateConfig::class)]
#[CoversClass(ShippingRateConfig::class)]
final class CommerceConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaults(): void
    {
        $config = new CommerceConfig();

        self::assertSame([], $config->taxRates);
        self::assertSame([], $config->shippingRates);
        self::assertSame('html', $config->invoiceRenderer);
        self::assertSame(30, $config->downloadTokenExpiryDays);
        self::assertSame(5, $config->maxDownloads);
        self::assertFalse($config->taxRequired);
        self::assertSame('EUR', $config->currency);
        self::assertSame('US', $config->sellerCountry);
        self::assertCount(27, $config->euCountryCodes);
        self::assertContains('DE', $config->euCountryCodes);
        self::assertContains('FR', $config->euCountryCodes);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = CommerceConfig::fromArray([
            'taxRates' => [
                ['category' => 'standard', 'rate' => 0.21, 'label' => 'VAT 21%', 'countryCodes' => ['NL', 'BE']],
                ['category' => 'reduced', 'rate' => 0.09, 'label' => 'VAT 9%', 'countryCodes' => ['NL']],
            ],
            'shippingRates' => [
                ['method' => 'standard', 'base_amount' => 500, 'per_item_amount' => 100, 'estimated_days' => 5],
                ['method' => 'express', 'base_amount' => 1500, 'free_threshold' => 10000],
            ],
            'invoiceRenderer' => 'pdf',
            'downloadTokenExpiryDays' => 14,
            'maxDownloads' => 3,
            'taxRequired' => true,
            'currency' => 'USD',
            'sellerCountry' => 'NL',
            'euCountryCodes' => ['NL', 'BE', 'DE'],
        ]);

        self::assertCount(2, $config->taxRates);
        self::assertSame('standard', $config->taxRates[0]->category);
        self::assertSame(0.21, $config->taxRates[0]->rate);
        self::assertSame('VAT 21%', $config->taxRates[0]->label);
        self::assertSame(['NL', 'BE'], $config->taxRates[0]->countryCodes);

        self::assertCount(2, $config->shippingRates);
        self::assertSame(ShippingMethod::Standard, $config->shippingRates[0]->method);
        self::assertSame(500, $config->shippingRates[0]->baseAmount);
        self::assertSame(100, $config->shippingRates[0]->perItemAmount);
        self::assertSame(5, $config->shippingRates[0]->estimatedDays);
        self::assertNull($config->shippingRates[0]->freeThreshold);

        self::assertSame(ShippingMethod::Express, $config->shippingRates[1]->method);
        self::assertSame(10000, $config->shippingRates[1]->freeThreshold);

        self::assertSame('pdf', $config->invoiceRenderer);
        self::assertSame(14, $config->downloadTokenExpiryDays);
        self::assertSame(3, $config->maxDownloads);
        self::assertTrue($config->taxRequired);
        self::assertSame('USD', $config->currency);
        self::assertSame('NL', $config->sellerCountry);
        self::assertSame(['NL', 'BE', 'DE'], $config->euCountryCodes);
    }

    #[Test]
    public function fromArrayWithEmptyDataUsesDefaults(): void
    {
        $config = CommerceConfig::fromArray([]);

        self::assertSame([], $config->taxRates);
        self::assertSame([], $config->shippingRates);
        self::assertSame('html', $config->invoiceRenderer);
        self::assertSame(30, $config->downloadTokenExpiryDays);
        self::assertSame(5, $config->maxDownloads);
        self::assertFalse($config->taxRequired);
        self::assertSame('EUR', $config->currency);
        self::assertSame('US', $config->sellerCountry);
        self::assertCount(27, $config->euCountryCodes);
    }

    #[Test]
    public function fromArraySkipsNonArrayTaxRateEntry(): void
    {
        $config = CommerceConfig::fromArray([
            'taxRates' => ['not-an-array', 42],
        ]);

        // Non-array entries are silently skipped
        self::assertSame([], $config->taxRates);
    }

    #[Test]
    public function fromArraySkipsNonArrayShippingRateEntry(): void
    {
        $config = CommerceConfig::fromArray([
            'shippingRates' => ['string-entry'],
        ]);

        // Non-array entries are silently skipped
        self::assertSame([], $config->shippingRates);
    }

    #[Test]
    public function fromArrayRejectsInvalidTypesWithTypeGuards(): void
    {
        $config = CommerceConfig::fromArray([
            'invoiceRenderer' => 42,
            'downloadTokenExpiryDays' => 'not-int',
            'maxDownloads' => 'not-int',
            'currency' => false,
            'sellerCountry' => 123,
        ]);

        // is_string(42) = false, key is set → ''
        self::assertSame('', $config->invoiceRenderer);
        // is_numeric('not-int') = false → default 30
        self::assertSame(30, $config->downloadTokenExpiryDays);
        // is_numeric('not-int') = false → default 5
        self::assertSame(5, $config->maxDownloads);
        // is_string(false) = false, key is set → ''
        self::assertSame('', $config->currency);
        // is_string(123) = false, key is set → ''
        self::assertSame('', $config->sellerCountry);
    }

    #[Test]
    public function taxRateConfigFromArrayWithMissingFields(): void
    {
        $rate = TaxRateConfig::fromArray([]);

        self::assertSame('', $rate->category);
        self::assertSame(0.0, $rate->rate);
        self::assertSame('', $rate->label);
        self::assertSame([], $rate->countryCodes);
    }

    #[Test]
    public function taxRateConfigFromArrayWithIntegerRate(): void
    {
        $rate = TaxRateConfig::fromArray([
            'category' => 'digital',
            'rate' => 21,
            'label' => 'Digital VAT',
            'countryCodes' => ['FR'],
        ]);

        self::assertSame('digital', $rate->category);
        self::assertSame(21.0, $rate->rate);
    }

    #[Test]
    public function shippingRateConfigFromArrayWithDefaults(): void
    {
        $rate = ShippingRateConfig::fromArray([]);

        self::assertSame(ShippingMethod::Standard, $rate->method);
        self::assertSame(0, $rate->baseAmount);
        self::assertSame(0, $rate->perItemAmount);
        self::assertNull($rate->freeThreshold);
        self::assertNull($rate->estimatedDays);
        self::assertSame([], $rate->countryCodes);
    }

    #[Test]
    public function shippingRateConfigFromArrayWithAllFields(): void
    {
        $rate = ShippingRateConfig::fromArray([
            'method' => 'overnight',
            'base_amount' => 3000,
            'per_item_amount' => 200,
            'free_threshold' => 50000,
            'estimated_days' => 1,
            'country_codes' => ['US', 'CA'],
        ]);

        self::assertSame(ShippingMethod::Overnight, $rate->method);
        self::assertSame(3000, $rate->baseAmount);
        self::assertSame(200, $rate->perItemAmount);
        self::assertSame(50000, $rate->freeThreshold);
        self::assertSame(1, $rate->estimatedDays);
        self::assertSame(['US', 'CA'], $rate->countryCodes);
    }
}
