<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Commerce\ShippingRateConfig;

#[CoversClass(ShippingRateConfig::class)]
final class ShippingRateConfigTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $config = new ShippingRateConfig(
            method: ShippingMethod::Express,
            baseAmount: 1500,
            perItemAmount: 200,
            freeThreshold: 5000,
            estimatedDays: 2,
            countryCodes: ['US', 'CA'],
        );

        self::assertSame(ShippingMethod::Express, $config->method);
        self::assertSame(1500, $config->baseAmount);
        self::assertSame(200, $config->perItemAmount);
        self::assertSame(5000, $config->freeThreshold);
        self::assertSame(2, $config->estimatedDays);
        self::assertSame(['US', 'CA'], $config->countryCodes);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = ShippingRateConfig::fromArray([
            'method' => 'express',
            'base_amount' => 1500,
            'per_item_amount' => 200,
            'free_threshold' => 5000,
            'estimated_days' => 3,
            'country_codes' => ['US', 'CA'],
        ]);

        self::assertSame(ShippingMethod::Express, $config->method);
        self::assertSame(1500, $config->baseAmount);
        self::assertSame(200, $config->perItemAmount);
        self::assertSame(5000, $config->freeThreshold);
        self::assertSame(3, $config->estimatedDays);
        self::assertSame(['US', 'CA'], $config->countryCodes);
    }

    #[Test]
    public function fromArrayWithMinimalData(): void
    {
        $config = ShippingRateConfig::fromArray([]);

        self::assertSame(ShippingMethod::Standard, $config->method);
        self::assertSame(0, $config->baseAmount);
        self::assertSame(0, $config->perItemAmount);
        self::assertNull($config->freeThreshold);
        self::assertNull($config->estimatedDays);
        self::assertSame([], $config->countryCodes);
    }

    #[Test]
    public function fromArrayUsesStandardWhenMethodNotProvided(): void
    {
        $config = ShippingRateConfig::fromArray([
            'base_amount' => 500,
        ]);

        self::assertSame(ShippingMethod::Standard, $config->method);
    }
}
