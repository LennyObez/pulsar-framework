<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\TaxRateConfig;
use Pulsar\Extension\Cms\Internal\Commerce\TaxCalculator;

#[CoversClass(TaxCalculator::class)]
final class TaxCalculatorEuConfigTest extends TestCase
{
    #[Test]
    public function defaultEuCodesEnableReverseCharge(): void
    {
        $config = new CommerceConfig(
            sellerCountry: 'DE',
            taxRates: [
                new TaxRateConfig(
                    category: 'standard',
                    rate: 0.21,
                    label: 'VAT 21%',
                    countryCodes: ['FR'],
                ),
            ],
        );

        $calculator = new TaxCalculator($config);

        $result = $calculator->calculate(
            items: [
                [
                    'productId' => 'p1',
                    'amount' => 10000,
                    'taxCategory' => 'standard',
                    'quantity' => 1,
                ],
            ],
            billingCountry: 'FR',
            vatNumber: 'FR12345678901',
        );

        self::assertTrue($result->reverseCharge);
        self::assertSame(0, $result->totalTax);
    }

    #[Test]
    public function emptyEuCodesDisableReverseCharge(): void
    {
        $config = new CommerceConfig(
            sellerCountry: 'DE',
            euCountryCodes: [],
            taxRates: [
                new TaxRateConfig(
                    category: 'standard',
                    rate: 0.21,
                    label: 'VAT 21%',
                    countryCodes: ['FR'],
                ),
            ],
        );

        $calculator = new TaxCalculator($config);

        $result = $calculator->calculate(
            items: [
                [
                    'productId' => 'p1',
                    'amount' => 10000,
                    'taxCategory' => 'standard',
                    'quantity' => 1,
                ],
            ],
            billingCountry: 'FR',
            vatNumber: 'FR12345678901',
        );

        self::assertFalse($result->reverseCharge);
        self::assertSame(2100, $result->totalTax);
    }

    #[Test]
    public function customEuCodesRestrictReverseCharge(): void
    {
        $config = new CommerceConfig(
            sellerCountry: 'DE',
            euCountryCodes: ['DE', 'FR'],
            taxRates: [
                new TaxRateConfig(
                    category: 'standard',
                    rate: 0.20,
                    label: 'VAT 20%',
                    countryCodes: ['IT'],
                ),
            ],
        );

        $calculator = new TaxCalculator($config);

        // Italy not in custom EU list, so no reverse charge even with VAT number
        $result = $calculator->calculate(
            items: [
                [
                    'productId' => 'p1',
                    'amount' => 5000,
                    'taxCategory' => 'standard',
                    'quantity' => 2,
                ],
            ],
            billingCountry: 'IT',
            vatNumber: 'IT12345678901',
        );

        self::assertFalse($result->reverseCharge);
        self::assertSame(2000, $result->totalTax);
    }
}
