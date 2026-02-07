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
    public function currencyValueIsIso4217(): void
    {
        self::assertSame('USD', Currency::USD->value);
        self::assertSame('EUR', Currency::EUR->value);
        self::assertSame('JPY', Currency::JPY->value);
        self::assertSame('BHD', Currency::BHD->value);
    }
}
