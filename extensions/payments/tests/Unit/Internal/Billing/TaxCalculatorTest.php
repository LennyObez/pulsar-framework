<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Billing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Internal\Billing\TaxCalculator;

final class TaxCalculatorTest extends TestCase
{
    #[Test]
    public function calculateReturnsDefaultVatRate(): void
    {
        $calculator = new TaxCalculator();
        $amount = Money::of(10000, Currency::EUR);

        $tax = $calculator->calculate($amount);

        // Default 20% VAT = 2000 basis points on 10000 = 2000
        self::assertSame(2000, $tax->amount);
        self::assertSame(Currency::EUR, $tax->currency);
    }

    #[Test]
    public function calculateHandlesZeroAmount(): void
    {
        $calculator = new TaxCalculator();
        $tax = $calculator->calculate(Money::zero(Currency::USD));

        self::assertSame(0, $tax->amount);
    }

    #[Test]
    public function calculateHandlesSmallAmounts(): void
    {
        $calculator = new TaxCalculator();
        $amount = Money::of(100, Currency::USD); // $1.00

        $tax = $calculator->calculate($amount);

        // 20% of 100 = 20
        self::assertSame(20, $tax->amount);
    }
}
