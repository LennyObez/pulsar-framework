<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Commerce\ShippingRateConfig;
use Pulsar\Extension\Cms\Internal\Commerce\ConfigurableShippingCalculator;

#[CoversClass(ConfigurableShippingCalculator::class)]
final class ConfigurableShippingCalculatorTest extends TestCase
{
    #[Test]
    public function calculateReturnsDigitalShippingForDigitalOnlyOrders(): void
    {
        $calculator = $this->createCalculator([]);

        $result = $calculator->calculate(
            [
                ['productId' => 'p1', 'amount' => 1000, 'quantity' => 1, 'digital' => true],
                ['productId' => 'p2', 'amount' => 2000, 'quantity' => 2, 'digital' => true],
            ],
            ['country' => 'US'],
        );

        self::assertSame(0, $result->amount);
        self::assertSame(ShippingMethod::Digital, $result->method);
        self::assertSame(0, $result->estimatedDays);
    }

    #[Test]
    public function calculateReturnsFallbackWhenNoRateConfigured(): void
    {
        $calculator = $this->createCalculator([]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 1000, 'quantity' => 1, 'digital' => false]],
            ['country' => 'US'],
        );

        self::assertSame(0, $result->amount);
        self::assertSame(ShippingMethod::Standard, $result->method);
        self::assertNull($result->estimatedDays);
    }

    #[Test]
    public function calculateUsesBaseAmountPlusPerItemForPhysicalItems(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                perItemAmount: 100,
                estimatedDays: 5,
            ),
        ]);

        $result = $calculator->calculate(
            [
                ['productId' => 'p1', 'amount' => 1000, 'quantity' => 2, 'digital' => false],
                ['productId' => 'p2', 'amount' => 500, 'quantity' => 1, 'digital' => false],
            ],
            ['country' => 'US'],
        );

        // base (500) + perItem (100) * 3 physical items = 800
        self::assertSame(800, $result->amount);
        self::assertSame(ShippingMethod::Standard, $result->method);
        self::assertSame(5, $result->estimatedDays);
    }

    #[Test]
    public function calculateExcludesDigitalItemsFromPhysicalCount(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                perItemAmount: 200,
                estimatedDays: 3,
            ),
        ]);

        $result = $calculator->calculate(
            [
                ['productId' => 'p1', 'amount' => 1000, 'quantity' => 1, 'digital' => false],
                ['productId' => 'p2', 'amount' => 2000, 'quantity' => 5, 'digital' => true],
            ],
            ['country' => 'US'],
        );

        // base (500) + perItem (200) * 1 physical item = 700
        self::assertSame(700, $result->amount);
    }

    #[Test]
    public function calculateAppliesFreeThresholdWhenSubtotalExceeds(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                perItemAmount: 100,
                freeThreshold: 5000,
                estimatedDays: 5,
            ),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 3000, 'quantity' => 2, 'digital' => false]],
            ['country' => 'US'],
        );

        // Subtotal 6000 >= threshold 5000 => free shipping
        self::assertSame(0, $result->amount);
        self::assertSame(ShippingMethod::Standard, $result->method);
        self::assertSame(5, $result->estimatedDays);
    }

    #[Test]
    public function calculateDoesNotApplyFreeThresholdWhenSubtotalBelow(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                perItemAmount: 100,
                freeThreshold: 10000,
                estimatedDays: 5,
            ),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 3000, 'quantity' => 1, 'digital' => false]],
            ['country' => 'US'],
        );

        // Subtotal 3000 < threshold 10000 => normal pricing
        self::assertSame(600, $result->amount);
    }

    #[Test]
    public function calculateMatchesExactCountryRateFirst(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 1000,
                estimatedDays: 10,
            ),
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 300,
                estimatedDays: 3,
                countryCodes: ['DE'],
            ),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 1000, 'quantity' => 1, 'digital' => false]],
            ['country' => 'DE'],
        );

        // Should match DE-specific rate
        self::assertSame(300, $result->amount);
        self::assertSame(3, $result->estimatedDays);
    }

    #[Test]
    public function calculateFallsBackToWildcardRateWhenNoCountryMatch(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 1500,
                estimatedDays: 14,
            ),
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 300,
                estimatedDays: 3,
                countryCodes: ['DE'],
            ),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 1000, 'quantity' => 1, 'digital' => false]],
            ['country' => 'JP'],
        );

        // JP not in DE list, falls back to wildcard (empty countryCodes)
        self::assertSame(1500, $result->amount);
        self::assertSame(14, $result->estimatedDays);
    }

    #[Test]
    public function calculateHandlesMissingCountryInAddress(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
            ),
        ]);

        $result = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 1000, 'quantity' => 1, 'digital' => false]],
            [],
        );

        // Empty country matches wildcard
        self::assertSame(500, $result->amount);
    }

    #[Test]
    public function availableMethodsReturnsMethodsForCountry(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                countryCodes: ['US', 'CA'],
            ),
            new ShippingRateConfig(
                method: ShippingMethod::Express,
                baseAmount: 1500,
                countryCodes: ['US'],
            ),
        ]);

        $methods = $calculator->availableMethods(['country' => 'US']);

        self::assertContains(ShippingMethod::Standard, $methods);
        self::assertContains(ShippingMethod::Express, $methods);
        self::assertContains(ShippingMethod::Digital, $methods);
    }

    #[Test]
    public function availableMethodsFiltersOutNonMatchingCountries(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Express,
                baseAmount: 1500,
                countryCodes: ['US'],
            ),
        ]);

        $methods = $calculator->availableMethods(['country' => 'DE']);

        self::assertNotContains(ShippingMethod::Express, $methods);
        // Digital is always available
        self::assertContains(ShippingMethod::Digital, $methods);
    }

    #[Test]
    public function availableMethodsAlwaysIncludesDigital(): void
    {
        $calculator = $this->createCalculator([]);

        $methods = $calculator->availableMethods(['country' => 'US']);

        self::assertContains(ShippingMethod::Digital, $methods);
    }

    #[Test]
    public function availableMethodsIncludesWildcardRates(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
            ),
        ]);

        $methods = $calculator->availableMethods(['country' => 'ANY']);

        self::assertContains(ShippingMethod::Standard, $methods);
    }

    #[Test]
    public function calculateSubtotalIncludesAllItemsTimesQuantity(): void
    {
        $calculator = $this->createCalculator([
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 0,
                freeThreshold: 10000,
                estimatedDays: 5,
            ),
        ]);

        // Subtotal: 2000*3 + 1000*2 = 8000, below 10000
        $result = $calculator->calculate(
            [
                ['productId' => 'p1', 'amount' => 2000, 'quantity' => 3, 'digital' => false],
                ['productId' => 'p2', 'amount' => 1000, 'quantity' => 2, 'digital' => false],
            ],
            ['country' => 'US'],
        );

        self::assertSame(0, $result->amount); // base 0, no per-item

        // Now with subtotal >= 10000: 5000*3 = 15000
        $result2 = $calculator->calculate(
            [['productId' => 'p1', 'amount' => 5000, 'quantity' => 3, 'digital' => false]],
            ['country' => 'US'],
        );

        self::assertSame(0, $result2->amount); // free due to threshold
    }

    /**
     * @param list<ShippingRateConfig> $rates
     */
    private function createCalculator(array $rates): ConfigurableShippingCalculator
    {
        return new ConfigurableShippingCalculator(
            new CommerceConfig(shippingRates: $rates),
        );
    }
}
