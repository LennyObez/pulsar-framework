<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Commerce\TaxLineItem;
use Pulsar\Extension\Cms\Commerce\TaxRateConfig;
use Pulsar\Extension\Cms\Commerce\TaxResult;

use function in_array;

#[CoversClass(TaxResult::class)]
#[CoversClass(TaxLineItem::class)]
#[CoversClass(TaxRateConfig::class)]
final class TaxCalculatorTest extends TestCase
{
    // ── Rate lookup by country + category ────────────────────────────

    #[Test]
    public function rateLookupByCountryAndCategory(): void
    {
        $calculator = $this->createCalculator([
            new TaxRateConfig('standard', 0.21, 'Belgian VAT', ['BE']),
            new TaxRateConfig('reduced', 0.06, 'Belgian Reduced', ['BE']),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 10000, 'taxCategory' => 'standard', 'quantity' => 1]],
            'BE',
        );

        self::assertCount(1, $result->items);
        self::assertSame(0.21, $result->items[0]->taxRate);
        self::assertSame(2100, $result->items[0]->taxAmount);
        self::assertSame(2100, $result->totalTax);
        self::assertFalse($result->reverseCharge);
    }

    // ── Zero-rate fallback when no matching rate ────────────────────

    #[Test]
    public function zeroRateFallbackWhenNoMatchingRate(): void
    {
        $calculator = $this->createCalculator([
            new TaxRateConfig('standard', 0.21, 'Belgian VAT', ['BE']),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 10000, 'taxCategory' => 'standard', 'quantity' => 1]],
            'US',
        );

        self::assertCount(1, $result->items);
        self::assertSame(0.0, $result->items[0]->taxRate);
        self::assertSame(0, $result->items[0]->taxAmount);
        self::assertSame(0, $result->totalTax);
    }

    // ── VAT reverse charge ──────────────────────────────────────────

    #[Test]
    public function vatReverseChargeZeroesTax(): void
    {
        $calculator = $this->createCalculator([
            new TaxRateConfig('standard', 0.21, 'Belgian VAT', ['BE']),
            new TaxRateConfig('standard', 0.19, 'German VAT', ['DE']),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 10000, 'taxCategory' => 'standard', 'quantity' => 1]],
            'DE',
            'DE123456789',
        );

        self::assertTrue($result->reverseCharge);
        self::assertSame(0, $result->totalTax);
        self::assertSame(0.0, $result->items[0]->taxRate);
    }

    // ── Multiple items with different categories ────────────────────

    #[Test]
    public function multipleItemsWithDifferentCategories(): void
    {
        $calculator = $this->createCalculator([
            new TaxRateConfig('standard', 0.21, 'Standard', ['BE']),
            new TaxRateConfig('reduced', 0.06, 'Reduced', ['BE']),
        ]);

        $result = $calculator->calculate(
            [
                ['productId' => 'p1', 'amount' => 10000, 'taxCategory' => 'standard', 'quantity' => 2],
                ['productId' => 'p2', 'amount' => 5000, 'taxCategory' => 'reduced', 'quantity' => 1],
            ],
            'BE',
        );

        self::assertCount(2, $result->items);

        $p1Tax = $this->findItemTax($result, 'p1');
        $p2Tax = $this->findItemTax($result, 'p2');

        self::assertSame(0.21, $p1Tax->taxRate);
        self::assertSame(4200, $p1Tax->taxAmount); // 20000 * 0.21
        self::assertSame(0.06, $p2Tax->taxRate);
        self::assertSame(300, $p2Tax->taxAmount); // 5000 * 0.06
        self::assertSame(4500, $result->totalTax);
    }

    // ── Rounding — all in minor units ───────────────────────────────

    #[Test]
    public function roundingInMinorUnits(): void
    {
        $calculator = $this->createCalculator([
            new TaxRateConfig('standard', 0.21, 'VAT 21%', ['NL']),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 999, 'taxCategory' => 'standard', 'quantity' => 1]],
            'NL',
        );

        // 999 * 0.21 = 209.79 → rounds to 210
        self::assertSame(210, $result->items[0]->taxAmount);
        self::assertSame(210, $result->totalTax);
    }

    // ── Null tax category falls back to zero ────────────────────────

    #[Test]
    public function nullTaxCategoryFallsBackToZero(): void
    {
        $calculator = $this->createCalculator([
            new TaxRateConfig('standard', 0.21, 'Standard', ['BE']),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 10000, 'taxCategory' => null, 'quantity' => 1]],
            'BE',
        );

        self::assertSame(0, $result->totalTax);
    }

    private function findItemTax(TaxResult $result, string $productId): TaxLineItem
    {
        foreach ($result->items as $item) {
            if ($item->productId === $productId) {
                return $item;
            }
        }

        self::fail("Tax line item not found for product: {$productId}");
    }

    /**
     * @param list<TaxRateConfig> $rates
     */
    private function createCalculator(array $rates): TaxCalculatorInterface
    {
        return new class ($rates) implements TaxCalculatorInterface {
            /** @param list<TaxRateConfig> $rates */
            public function __construct(private readonly array $rates) {}

            public function calculate(array $items, string $billingCountry, ?string $vatNumber = null): TaxResult
            {
                $isEu = in_array($billingCountry, ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'], true);
                $reverseCharge = $isEu && $vatNumber !== null;

                $lineItems = [];
                $totalTax = 0;

                foreach ($items as $item) {
                    $rate = $this->findRate($item['taxCategory'], $billingCountry);

                    if ($reverseCharge) {
                        $rate = 0.0;
                    }

                    $lineTotal = $item['amount'] * $item['quantity'];
                    $taxAmount = (int) round($lineTotal * $rate);
                    $totalTax += $taxAmount;

                    $lineItems[] = new TaxLineItem(
                        productId: $item['productId'],
                        taxRate: $rate,
                        taxAmount: $taxAmount,
                    );
                }

                return new TaxResult(
                    items: $lineItems,
                    totalTax: $totalTax,
                    reverseCharge: $reverseCharge,
                );
            }

            private function findRate(?string $category, string $country): float
            {
                if ($category === null) {
                    return 0.0;
                }

                foreach ($this->rates as $rateConfig) {
                    if ($rateConfig->category === $category && in_array($country, $rateConfig->countryCodes, true)) {
                        return $rateConfig->rate;
                    }
                }

                return 0.0;
            }
        };
    }
}
