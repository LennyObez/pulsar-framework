<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;

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
    public function usdSymbolIsDollar(): void
    {
        self::assertSame('$', Currency::USD->symbol());
    }

    #[Test]
    public function eurSymbolIsEuro(): void
    {
        self::assertSame("\u{20AC}", Currency::EUR->symbol());
    }

    #[Test]
    public function gbpSymbolIsPound(): void
    {
        self::assertSame("\u{00A3}", Currency::GBP->symbol());
    }

    #[Test]
    public function jpySymbolIsYen(): void
    {
        self::assertSame("\u{00A5}", Currency::JPY->symbol());
    }

    #[Test]
    public function chfSymbolIsLetterCode(): void
    {
        self::assertSame('CHF', Currency::CHF->symbol());
    }

    #[Test]
    public function fromStringReturnsCorrectCase(): void
    {
        self::assertSame(Currency::USD, Currency::from('USD'));
        self::assertSame(Currency::EUR, Currency::from('EUR'));
    }
}
