<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;

#[CoversClass(CommerceConfig::class)]
final class CommerceConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreReasonable(): void
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
        self::assertContains('DE', $config->euCountryCodes);
        self::assertContains('FR', $config->euCountryCodes);
        self::assertCount(27, $config->euCountryCodes);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CommerceConfig::fromArray([]);

        self::assertSame([], $config->taxRates);
        self::assertSame([], $config->shippingRates);
        self::assertSame('html', $config->invoiceRenderer);
        self::assertSame(30, $config->downloadTokenExpiryDays);
        self::assertSame(5, $config->maxDownloads);
    }

    #[Test]
    public function fromArrayParsesScalarValues(): void
    {
        $config = CommerceConfig::fromArray([
            'invoiceRenderer' => 'pdf',
            'downloadTokenExpiryDays' => 14,
            'maxDownloads' => 3,
            'taxRequired' => true,
            'currency' => 'USD',
            'sellerCountry' => 'DE',
        ]);

        self::assertSame('pdf', $config->invoiceRenderer);
        self::assertSame(14, $config->downloadTokenExpiryDays);
        self::assertSame(3, $config->maxDownloads);
        self::assertTrue($config->taxRequired);
        self::assertSame('USD', $config->currency);
        self::assertSame('DE', $config->sellerCountry);
    }

    #[Test]
    public function fromArrayParsesTaxRates(): void
    {
        $config = CommerceConfig::fromArray([
            'taxRates' => [
                ['category' => 'standard', 'rate' => 0.21, 'label' => 'BTW 21%', 'countryCodes' => ['NL', 'BE']],
            ],
        ]);

        self::assertCount(1, $config->taxRates);
        self::assertSame('standard', $config->taxRates[0]->category);
        self::assertSame(0.21, $config->taxRates[0]->rate);
        self::assertSame('BTW 21%', $config->taxRates[0]->label);
        self::assertSame(['NL', 'BE'], $config->taxRates[0]->countryCodes);
    }

    #[Test]
    public function fromArrayParsesShippingRates(): void
    {
        $config = CommerceConfig::fromArray([
            'shippingRates' => [
                ['method' => 'express', 'base_amount' => 1500, 'per_item_amount' => 200],
            ],
        ]);

        self::assertCount(1, $config->shippingRates);
        self::assertSame(1500, $config->shippingRates[0]->baseAmount);
        self::assertSame(200, $config->shippingRates[0]->perItemAmount);
    }

    #[Test]
    public function fromArraySkipsNonArrayRateEntries(): void
    {
        $config = CommerceConfig::fromArray([
            'taxRates' => ['not-an-array', 42],
            'shippingRates' => [null, 'invalid'],
        ]);

        self::assertSame([], $config->taxRates);
        self::assertSame([], $config->shippingRates);
    }

    #[Test]
    public function fromArrayOverridesEuCountryCodes(): void
    {
        $config = CommerceConfig::fromArray([
            'euCountryCodes' => ['DE', 'FR'],
        ]);

        self::assertSame(['DE', 'FR'], $config->euCountryCodes);
    }
}
