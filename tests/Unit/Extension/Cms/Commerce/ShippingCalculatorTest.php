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
final class ShippingCalculatorTest extends TestCase
{
    #[Test]
    public function digital_only_orders_get_free_shipping(): void
    {
        $config = new CommerceConfig(shippingRates: [
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
            ),
        ]);

        $calculator = new ConfigurableShippingCalculator($config);

        $result = $calculator->calculate(
            items: [
                ['productId' => 'p1', 'amount' => 1999, 'quantity' => 1, 'digital' => true],
                ['productId' => 'p2', 'amount' => 999, 'quantity' => 2, 'digital' => true],
            ],
            address: ['country' => 'US'],
        );

        self::assertSame(0, $result->amount);
        self::assertSame(ShippingMethod::Digital, $result->method);
        self::assertSame(0, $result->estimatedDays);
    }

    #[Test]
    public function standard_shipping_calculates_from_config(): void
    {
        $config = new CommerceConfig(shippingRates: [
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                perItemAmount: 100,
                estimatedDays: 5,
            ),
        ]);

        $calculator = new ConfigurableShippingCalculator($config);

        $result = $calculator->calculate(
            items: [
                ['productId' => 'p1', 'amount' => 2999, 'quantity' => 2, 'digital' => false],
                ['productId' => 'p2', 'amount' => 999, 'quantity' => 1, 'digital' => false],
            ],
            address: ['country' => 'US'],
        );

        // base 500 + (3 physical items * 100) = 800
        self::assertSame(800, $result->amount);
        self::assertSame(ShippingMethod::Standard, $result->method);
        self::assertSame(5, $result->estimatedDays);
    }

    #[Test]
    public function free_threshold_waives_shipping(): void
    {
        $config = new CommerceConfig(shippingRates: [
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                perItemAmount: 100,
                freeThreshold: 5000,
                estimatedDays: 5,
            ),
        ]);

        $calculator = new ConfigurableShippingCalculator($config);

        // Subtotal: 2999 * 2 = 5998 > 5000 threshold
        $result = $calculator->calculate(
            items: [
                ['productId' => 'p1', 'amount' => 2999, 'quantity' => 2, 'digital' => false],
            ],
            address: ['country' => 'US'],
        );

        self::assertSame(0, $result->amount);
        self::assertSame(ShippingMethod::Standard, $result->method);
        self::assertSame(5, $result->estimatedDays);
    }

    #[Test]
    public function free_threshold_not_met_charges_shipping(): void
    {
        $config = new CommerceConfig(shippingRates: [
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                freeThreshold: 10000,
                estimatedDays: 5,
            ),
        ]);

        $calculator = new ConfigurableShippingCalculator($config);

        // Subtotal: 2999 < 10000 threshold
        $result = $calculator->calculate(
            items: [
                ['productId' => 'p1', 'amount' => 2999, 'quantity' => 1, 'digital' => false],
            ],
            address: ['country' => 'US'],
        );

        self::assertSame(500, $result->amount);
        self::assertSame(ShippingMethod::Standard, $result->method);
    }

    #[Test]
    public function country_specific_rate_takes_precedence(): void
    {
        $config = new CommerceConfig(shippingRates: [
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 1500,
                estimatedDays: 10,
                countryCodes: ['AU'],
            ),
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                estimatedDays: 5,
            ),
        ]);

        $calculator = new ConfigurableShippingCalculator($config);

        $auResult = $calculator->calculate(
            items: [['productId' => 'p1', 'amount' => 2000, 'quantity' => 1, 'digital' => false]],
            address: ['country' => 'AU'],
        );

        self::assertSame(1500, $auResult->amount);
        self::assertSame(10, $auResult->estimatedDays);

        $usResult = $calculator->calculate(
            items: [['productId' => 'p1', 'amount' => 2000, 'quantity' => 1, 'digital' => false]],
            address: ['country' => 'US'],
        );

        self::assertSame(500, $usResult->amount);
        self::assertSame(5, $usResult->estimatedDays);
    }

    #[Test]
    public function mixed_digital_and_physical_only_counts_physical(): void
    {
        $config = new CommerceConfig(shippingRates: [
            new ShippingRateConfig(
                method: ShippingMethod::Standard,
                baseAmount: 500,
                perItemAmount: 200,
            ),
        ]);

        $calculator = new ConfigurableShippingCalculator($config);

        $result = $calculator->calculate(
            items: [
                ['productId' => 'p1', 'amount' => 2999, 'quantity' => 1, 'digital' => false],
                ['productId' => 'p2', 'amount' => 999, 'quantity' => 3, 'digital' => true],
            ],
            address: ['country' => 'US'],
        );

        // base 500 + (1 physical item * 200) = 700
        self::assertSame(700, $result->amount);
        self::assertSame(ShippingMethod::Standard, $result->method);
    }

    #[Test]
    public function no_configured_rates_returns_zero_cost(): void
    {
        $config = new CommerceConfig(shippingRates: []);
        $calculator = new ConfigurableShippingCalculator($config);

        $result = $calculator->calculate(
            items: [['productId' => 'p1', 'amount' => 2000, 'quantity' => 1, 'digital' => false]],
            address: ['country' => 'US'],
        );

        self::assertSame(0, $result->amount);
        self::assertSame(ShippingMethod::Standard, $result->method);
        self::assertNull($result->estimatedDays);
    }

    #[Test]
    public function available_methods_returns_matching_methods(): void
    {
        $config = new CommerceConfig(shippingRates: [
            new ShippingRateConfig(method: ShippingMethod::Standard, baseAmount: 500),
            new ShippingRateConfig(method: ShippingMethod::Express, baseAmount: 1200, countryCodes: ['US']),
            new ShippingRateConfig(method: ShippingMethod::Overnight, baseAmount: 2500, countryCodes: ['US']),
        ]);

        $calculator = new ConfigurableShippingCalculator($config);

        $usMethods = $calculator->availableMethods(['country' => 'US']);
        self::assertContains(ShippingMethod::Standard, $usMethods);
        self::assertContains(ShippingMethod::Express, $usMethods);
        self::assertContains(ShippingMethod::Overnight, $usMethods);
        self::assertContains(ShippingMethod::Digital, $usMethods);

        $beMethods = $calculator->availableMethods(['country' => 'BE']);
        self::assertContains(ShippingMethod::Standard, $beMethods);
        self::assertNotContains(ShippingMethod::Express, $beMethods);
        self::assertNotContains(ShippingMethod::Overnight, $beMethods);
        self::assertContains(ShippingMethod::Digital, $beMethods);
    }

    #[Test]
    public function shipping_rate_config_from_array(): void
    {
        $config = ShippingRateConfig::fromArray([
            'method' => 'express',
            'base_amount' => 1200,
            'per_item_amount' => 150,
            'free_threshold' => 10000,
            'estimated_days' => 2,
            'country_codes' => ['US', 'CA'],
        ]);

        self::assertSame(ShippingMethod::Express, $config->method);
        self::assertSame(1200, $config->baseAmount);
        self::assertSame(150, $config->perItemAmount);
        self::assertSame(10000, $config->freeThreshold);
        self::assertSame(2, $config->estimatedDays);
        self::assertSame(['US', 'CA'], $config->countryCodes);
    }

    #[Test]
    public function shipping_rate_config_from_array_defaults(): void
    {
        $config = ShippingRateConfig::fromArray([]);

        self::assertSame(ShippingMethod::Standard, $config->method);
        self::assertSame(0, $config->baseAmount);
        self::assertSame(0, $config->perItemAmount);
        self::assertNull($config->freeThreshold);
        self::assertNull($config->estimatedDays);
        self::assertSame([], $config->countryCodes);
    }
}
