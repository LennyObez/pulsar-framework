<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\InvoiceTaxBreakdown;
use Pulsar\Extension\Payments\Domain\Money;

final class InvoiceTaxBreakdownTest extends TestCase
{
    #[Test]
    #[DataProvider('countryTaxProvider')]
    public function calculatesCorrectTaxForCountry(
        string $jurisdiction,
        int $rateBp,
        int $taxableAmountMinor,
        int $expectedTaxMinor,
    ): void {
        $taxable = Money::of($taxableAmountMinor, Currency::EUR);
        $taxAmount = $taxable->percentage($rateBp);

        $breakdown = new InvoiceTaxBreakdown(
            taxCategoryCode: 'S',
            taxRatePercent: $rateBp,
            taxableAmount: $taxable,
            taxAmount: $taxAmount,
            jurisdiction: $jurisdiction,
        );

        self::assertSame($expectedTaxMinor, $breakdown->taxAmount->amount);
        self::assertSame($rateBp, $breakdown->taxRatePercent);
        self::assertSame($jurisdiction, $breakdown->jurisdiction);
        self::assertSame('S', $breakdown->taxCategoryCode);
    }

    /**
     * @return iterable<string, array{string, int, int, int}>
     */
    public static function countryTaxProvider(): iterable
    {
        // Country, rateBasisPoints, taxableAmountMinor, expectedTaxMinor
        yield 'Belgium 21%' => ['BE', 2100, 10000, 2100];
        yield 'Germany 19%' => ['DE', 1900, 10000, 1900];
        yield 'France 20%' => ['FR', 2000, 10000, 2000];
        yield 'Netherlands 21%' => ['NL', 2100, 10000, 2100];
        yield 'Italy 22%' => ['IT', 2200, 10000, 2200];
        yield 'Spain 21%' => ['ES', 2100, 10000, 2100];
        yield 'Sweden 25%' => ['SE', 2500, 10000, 2500];
        yield 'Hungary 27%' => ['HU', 2700, 10000, 2700];
        yield 'Luxembourg 17%' => ['LU', 1700, 10000, 1700];
        yield 'Switzerland 8.1%' => ['CH', 810, 10000, 810];
        yield 'UK 20%' => ['GB', 2000, 10000, 2000];
        yield 'Zero-rated' => ['BE', 0, 10000, 0];
    }

    #[Test]
    public function zeroRatedTaxBreakdown(): void
    {
        $taxable = Money::of(50000, Currency::EUR);

        $breakdown = new InvoiceTaxBreakdown(
            taxCategoryCode: 'Z',
            taxRatePercent: 0,
            taxableAmount: $taxable,
            taxAmount: Money::zero(Currency::EUR),
            jurisdiction: 'BE',
        );

        self::assertSame('Z', $breakdown->taxCategoryCode);
        self::assertSame(0, $breakdown->taxRatePercent);
        self::assertTrue($breakdown->taxAmount->isZero());
    }

    #[Test]
    public function exemptTaxBreakdown(): void
    {
        $taxable = Money::of(100000, Currency::EUR);

        $breakdown = new InvoiceTaxBreakdown(
            taxCategoryCode: 'E',
            taxRatePercent: 0,
            taxableAmount: $taxable,
            taxAmount: Money::zero(Currency::EUR),
            jurisdiction: 'DE',
        );

        self::assertSame('E', $breakdown->taxCategoryCode);
        self::assertSame(0, $breakdown->taxRatePercent);
        self::assertSame(100000, $breakdown->taxableAmount->amount);
    }

    #[Test]
    public function reverseChargeTaxBreakdown(): void
    {
        $taxable = Money::of(75000, Currency::EUR);

        $breakdown = new InvoiceTaxBreakdown(
            taxCategoryCode: 'AE',
            taxRatePercent: 0,
            taxableAmount: $taxable,
            taxAmount: Money::zero(Currency::EUR),
            jurisdiction: 'NL',
        );

        self::assertSame('AE', $breakdown->taxCategoryCode);
    }

    #[Test]
    public function roundingOnNonEvenTaxAmounts(): void
    {
        // 21% of 9999 cents = 2099.79 → rounds to 2100
        $taxable = Money::of(9999, Currency::EUR);
        $taxAmount = $taxable->percentage(2100);

        $breakdown = new InvoiceTaxBreakdown(
            taxCategoryCode: 'S',
            taxRatePercent: 2100,
            taxableAmount: $taxable,
            taxAmount: $taxAmount,
            jurisdiction: 'BE',
        );

        self::assertSame(2100, $breakdown->taxAmount->amount);
    }

    #[Test]
    public function differentCurrencies(): void
    {
        $taxable = Money::of(100000, Currency::GBP);
        $taxAmount = $taxable->percentage(2000);

        $breakdown = new InvoiceTaxBreakdown(
            taxCategoryCode: 'S',
            taxRatePercent: 2000,
            taxableAmount: $taxable,
            taxAmount: $taxAmount,
            jurisdiction: 'GB',
        );

        self::assertSame(Currency::GBP, $breakdown->taxableAmount->currency);
        self::assertSame(Currency::GBP, $breakdown->taxAmount->currency);
        self::assertSame(20000, $breakdown->taxAmount->amount);
    }
}
