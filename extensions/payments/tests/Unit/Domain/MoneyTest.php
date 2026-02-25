<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\RoundingMode;
use Pulsar\Extension\Payments\Exception\MoneyException;

final class MoneyTest extends TestCase
{
    #[Test]
    public function ofCreatesMoneyWithAmountAndCurrency(): void
    {
        $money = Money::of(1050, Currency::USD);

        self::assertSame(1050, $money->amount);
        self::assertSame(Currency::USD, $money->currency);
    }

    #[Test]
    public function ofRejectsNegativeAmount(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessage('non-negative');
        (void) Money::of(-100, Currency::USD);
    }

    #[Test]
    public function zeroCreatesZeroMoney(): void
    {
        $money = Money::zero(Currency::EUR);

        self::assertSame(0, $money->amount);
        self::assertTrue($money->isZero());
    }

    #[Test]
    public function addReturnsSumOfSameCurrency(): void
    {
        $a = Money::of(1000, Currency::USD);
        $b = Money::of(500, Currency::USD);
        $result = $a->add($b);

        self::assertSame(1500, $result->amount);
        self::assertSame(Currency::USD, $result->currency);
    }

    #[Test]
    public function addIsImmutable(): void
    {
        $a = Money::of(1000, Currency::USD);
        $b = Money::of(500, Currency::USD);
        (void) $a->add($b);

        self::assertSame(1000, $a->amount);
    }

    #[Test]
    public function addThrowsOnCurrencyMismatch(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessage('mismatch');
        (void) Money::of(100, Currency::USD)->add(Money::of(200, Currency::EUR));
    }

    #[Test]
    public function subtractReturnsCorrectDifference(): void
    {
        $result = Money::of(1000, Currency::USD)->subtract(Money::of(300, Currency::USD));

        self::assertSame(700, $result->amount);
    }

    #[Test]
    public function subtractThrowsOnNegativeResult(): void
    {
        $this->expectException(MoneyException::class);
        (void) Money::of(100, Currency::USD)->subtract(Money::of(200, Currency::USD));
    }

    #[Test]
    public function subtractThrowsOnCurrencyMismatch(): void
    {
        $this->expectException(MoneyException::class);
        (void) Money::of(1000, Currency::USD)->subtract(Money::of(100, Currency::EUR));
    }

    #[Test]
    public function multiplyByPositiveFactor(): void
    {
        $result = Money::of(500, Currency::USD)->multiply(3);

        self::assertSame(1500, $result->amount);
    }

    #[Test]
    public function multiplyByZeroReturnsZero(): void
    {
        $result = Money::of(500, Currency::USD)->multiply(0);

        self::assertTrue($result->isZero());
    }

    #[Test]
    public function multiplyThrowsOnNegativeFactor(): void
    {
        $this->expectException(MoneyException::class);
        (void) Money::of(500, Currency::USD)->multiply(-1);
    }

    #[Test]
    public function percentageComputes20Percent(): void
    {
        // 2000 basis points = 20%
        $result = Money::of(10000, Currency::USD)->percentage(2000);

        self::assertSame(2000, $result->amount);
    }

    #[Test]
    public function percentageThrowsOnNegativeBasisPoints(): void
    {
        $this->expectException(MoneyException::class);
        (void) Money::of(1000, Currency::USD)->percentage(-100);
    }

    #[Test]
    public function percentageAppliesFloorRounding(): void
    {
        // 33.33% of 100 = 33.33, floor = 33
        $result = Money::of(100, Currency::USD)->percentage(3333, RoundingMode::Floor);

        self::assertSame(33, $result->amount);
    }

    #[Test]
    public function percentageAppliesCeilingRounding(): void
    {
        // 33.33% of 100 = 33.33, ceiling = 34
        $result = Money::of(100, Currency::USD)->percentage(3333, RoundingMode::Ceiling);

        self::assertSame(34, $result->amount);
    }

    #[Test]
    public function allocateDistributesEvenly(): void
    {
        $parts = Money::of(1000, Currency::USD)->allocate(4);

        self::assertCount(4, $parts);
        self::assertSame(250, $parts[0]->amount);
        self::assertSame(250, $parts[1]->amount);
    }

    #[Test]
    public function allocateDistributesRemainderOneUnitAtATime(): void
    {
        $parts = Money::of(1003, Currency::USD)->allocate(4);

        self::assertCount(4, $parts);
        // 1003 / 4 = 250 remainder 3 => first 3 get 251, last gets 250
        self::assertSame(251, $parts[0]->amount);
        self::assertSame(251, $parts[1]->amount);
        self::assertSame(251, $parts[2]->amount);
        self::assertSame(250, $parts[3]->amount);

        $total = $parts[0]->amount + $parts[1]->amount + $parts[2]->amount + $parts[3]->amount;
        self::assertSame(1003, $total);
    }

    #[Test]
    public function allocateThrowsOnZeroParts(): void
    {
        $this->expectException(MoneyException::class);
        (void) Money::of(1000, Currency::USD)->allocate(0);
    }

    #[Test]
    public function allocateThrowsOnNegativeParts(): void
    {
        $this->expectException(MoneyException::class);
        (void) Money::of(1000, Currency::USD)->allocate(-1);
    }

    #[Test]
    public function formatWithTwoMinorDigits(): void
    {
        self::assertSame('10.50', Money::of(1050, Currency::USD)->format());
        self::assertSame('0.01', Money::of(1, Currency::USD)->format());
        self::assertSame('100.00', Money::of(10000, Currency::EUR)->format());
    }

    #[Test]
    public function formatWithZeroMinorDigits(): void
    {
        self::assertSame('1000', Money::of(1000, Currency::JPY)->format());
    }

    #[Test]
    public function formatWithThreeMinorDigits(): void
    {
        self::assertSame('1.234', Money::of(1234, Currency::BHD)->format());
    }

    #[Test]
    public function equalsReturnsTrueForSameAmountAndCurrency(): void
    {
        $a = Money::of(1000, Currency::USD);
        $b = Money::of(1000, Currency::USD);

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentAmount(): void
    {
        self::assertFalse(Money::of(1000, Currency::USD)->equals(Money::of(999, Currency::USD)));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentCurrency(): void
    {
        self::assertFalse(Money::of(1000, Currency::USD)->equals(Money::of(1000, Currency::EUR)));
    }

    #[Test]
    public function isZeroReturnsTrueForZeroAmount(): void
    {
        self::assertTrue(Money::of(0, Currency::USD)->isZero());
    }

    #[Test]
    public function isZeroReturnsFalseForNonZero(): void
    {
        self::assertFalse(Money::of(1, Currency::USD)->isZero());
    }
}
