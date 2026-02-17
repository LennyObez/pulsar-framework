<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\TaxRateConfig;

#[CoversClass(TaxRateConfig::class)]
final class TaxRateConfigTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $config = new TaxRateConfig(
            category: 'reduced',
            rate: 0.09,
            label: 'BTW 9%',
            countryCodes: ['NL'],
        );

        self::assertSame('reduced', $config->category);
        self::assertSame(0.09, $config->rate);
        self::assertSame('BTW 9%', $config->label);
        self::assertSame(['NL'], $config->countryCodes);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = TaxRateConfig::fromArray([
            'category' => 'digital',
            'rate' => 0.21,
            'label' => 'Digital VAT',
            'countryCodes' => ['DE', 'FR'],
        ]);

        self::assertSame('digital', $config->category);
        self::assertSame(0.21, $config->rate);
        self::assertSame('Digital VAT', $config->label);
        self::assertSame(['DE', 'FR'], $config->countryCodes);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = TaxRateConfig::fromArray([]);

        self::assertSame('', $config->category);
        self::assertSame(0.0, $config->rate);
        self::assertSame('', $config->label);
        self::assertSame([], $config->countryCodes);
    }

    #[Test]
    public function fromArrayAcceptsIntegerRate(): void
    {
        $config = TaxRateConfig::fromArray([
            'rate' => 1,
        ]);

        self::assertSame(1.0, $config->rate);
    }

    #[Test]
    public function fromArrayHandlesNonStringCountryCodes(): void
    {
        $config = TaxRateConfig::fromArray([
            'countryCodes' => [42, 'US', null],
        ]);

        self::assertSame(['', 'US', ''], $config->countryCodes);
    }
}
