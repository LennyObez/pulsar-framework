<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;

#[CoversClass(Currency::class)]
final class CurrencyTest extends TestCase
{
    #[Test]
    public function usdHasTwoMinorDigits(): void
    {
        self::assertSame(2, Currency::USD->minorDigits());
    }

    #[Test]
    public function jpyHasZeroMinorDigits(): void
    {
        self::assertSame(0, Currency::JPY->minorDigits());
    }

    #[Test]
    public function bhdHasThreeMinorDigits(): void
    {
        self::assertSame(3, Currency::BHD->minorDigits());
    }

    #[Test]
    public function kwdHasThreeMinorDigits(): void
    {
        self::assertSame(3, Currency::KWD->minorDigits());
    }

    #[Test]
    public function hufHasZeroMinorDigits(): void
    {
        self::assertSame(0, Currency::HUF->minorDigits());
    }

    #[Test]
    public function eurHasTwoMinorDigits(): void
    {
        self::assertSame(2, Currency::EUR->minorDigits());
    }

    #[Test]
    public function usdSymbolIsDollarSign(): void
    {
        self::assertSame('$', Currency::USD->symbol());
    }

    #[Test]
    public function eurSymbolIsEuro(): void
    {
        self::assertSame('€', Currency::EUR->symbol());
    }

    #[Test]
    public function gbpSymbolIsPound(): void
    {
        self::assertSame('£', Currency::GBP->symbol());
    }

    #[Test]
    public function jpySymbolIsYen(): void
    {
        self::assertSame('¥', Currency::JPY->symbol());
    }

    #[Test]
    public function chfSymbolIsCHF(): void
    {
        self::assertSame('CHF', Currency::CHF->symbol());
    }

    #[Test]
    public function sekSymbolIsKr(): void
    {
        self::assertSame('kr', Currency::SEK->symbol());
    }

    #[Test]
    public function nokSymbolIsKr(): void
    {
        self::assertSame('kr', Currency::NOK->symbol());
    }

    #[Test]
    public function dkkSymbolIsKr(): void
    {
        self::assertSame('kr', Currency::DKK->symbol());
    }

    #[Test]
    public function brlSymbolIsReal(): void
    {
        self::assertSame('R$', Currency::BRL->symbol());
    }

    #[Test]
    public function inrSymbolIsRupee(): void
    {
        self::assertSame('₹', Currency::INR->symbol());
    }

    #[Test]
    public function zarSymbolIsRand(): void
    {
        self::assertSame('R', Currency::ZAR->symbol());
    }

    #[Test]
    public function plnSymbolIsZloty(): void
    {
        self::assertSame('zł', Currency::PLN->symbol());
    }

    #[Test]
    public function czkSymbolIsKoruna(): void
    {
        self::assertSame('Kč', Currency::CZK->symbol());
    }

    #[Test]
    public function hufSymbolIsForint(): void
    {
        self::assertSame('Ft', Currency::HUF->symbol());
    }

    #[Test]
    public function bhdSymbolIsCurrencyCode(): void
    {
        self::assertSame('BHD', Currency::BHD->symbol());
    }

    #[Test]
    public function cadSymbolIsDollar(): void
    {
        self::assertSame('$', Currency::CAD->symbol());
    }

    #[Test]
    public function audSymbolIsDollar(): void
    {
        self::assertSame('$', Currency::AUD->symbol());
    }

    #[Test]
    public function currencyValueIsIso4217(): void
    {
        self::assertSame('USD', Currency::USD->value);
        self::assertSame('EUR', Currency::EUR->value);
        self::assertSame('JPY', Currency::JPY->value);
        self::assertSame('BHD', Currency::BHD->value);
    }

    #[Test]
    public function omrHasThreeMinorDigits(): void
    {
        self::assertSame(3, Currency::OMR->minorDigits());
    }
}
