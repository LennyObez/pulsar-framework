<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\RoundingMode;
use Pulsar\Extension\Payments\Exception\MoneyException;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    #[Test]
    public function ofCreatesMoneyWithPositiveAmount(): void
    {
        $money = Money::of(1050, Currency::USD);

        self::assertSame(1050, $money->amount);
        self::assertSame(Currency::USD, $money->currency);
    }

    #[Test]
    public function ofAcceptsZero(): void
    {
        $money = Money::of(0, Currency::EUR);

        self::assertSame(0, $money->amount);
    }

    #[Test]
    public function ofRejectsNegativeAmount(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('non-negative');

        (void) Money::of(-1, Currency::USD);
    }

    #[Test]
    public function zeroCreatesZeroMoney(): void
    {
        $money = Money::zero(Currency::GBP);

        self::assertSame(0, $money->amount);
        self::assertSame(Currency::GBP, $money->currency);
        self::assertTrue($money->isZero());
    }

    #[Test]
    public function addSameCurrency(): void
    {
        $a = Money::of(100, Currency::USD);
        $b = Money::of(250, Currency::USD);

        $result = $a->add($b);

        self::assertSame(350, $result->amount);
        self::assertSame(Currency::USD, $result->currency);
    }

    #[Test]
    public function addRejectsCrossCurrency(): void
    {
        $a = Money::of(100, Currency::USD);
        $b = Money::of(100, Currency::EUR);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('Currency mismatch');

        (void) $a->add($b);
    }

    #[Test]
    public function subtractSameCurrency(): void
    {
        $a = Money::of(500, Currency::USD);
        $b = Money::of(200, Currency::USD);

        $result = $a->subtract($b);

        self::assertSame(300, $result->amount);
    }

    #[Test]
    public function subtractRejectsCrossCurrency(): void
    {
        $a = Money::of(100, Currency::USD);
        $b = Money::of(50, Currency::GBP);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('Currency mismatch');

        (void) $a->subtract($b);
    }

    #[Test]
    public function subtractRejectsNegativeResult(): void
    {
        $a = Money::of(100, Currency::USD);
        $b = Money::of(200, Currency::USD);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('non-negative');

        (void) $a->subtract($b);
    }

    #[Test]
    public function multiplyByPositiveFactor(): void
    {
        $money = Money::of(100, Currency::USD);

        $result = $money->multiply(3);

        self::assertSame(300, $result->amount);
    }

    #[Test]
    public function multiplyByZero(): void
    {
        $money = Money::of(100, Currency::USD);

        $result = $money->multiply(0);

        self::assertSame(0, $result->amount);
    }

    #[Test]
    public function multiplyRejectsNegativeFactor(): void
    {
        $money = Money::of(100, Currency::USD);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('non-negative');

        (void) $money->multiply(-1);
    }

    #[Test]
    public function percentageWithHalfUp(): void
    {
        $money = Money::of(10000, Currency::USD);

        // 25% = 2500 basis points
        $result = $money->percentage(2500, RoundingMode::HalfUp);

        self::assertSame(2500, $result->amount);
    }

    #[Test]
    public function percentageWithRounding(): void
    {
        $money = Money::of(333, Currency::USD);

        // 50% of 333 = 166.5 → rounds to 167 with HalfUp
        $result = $money->percentage(5000, RoundingMode::HalfUp);
        self::assertSame(167, $result->amount);

        // Floor rounds down
        $resultFloor = $money->percentage(5000, RoundingMode::Floor);
        self::assertSame(166, $resultFloor->amount);
    }

    #[Test]
    public function percentageRejectsNegativeBasisPoints(): void
    {
        $money = Money::of(100, Currency::USD);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('non-negative');

        (void) $money->percentage(-100);
    }

    #[Test]
    public function allocateDistributesEvenly(): void
    {
        $money = Money::of(300, Currency::USD);

        $parts = $money->allocate(3);

        self::assertCount(3, $parts);
        self::assertSame(100, $parts[0]->amount);
        self::assertSame(100, $parts[1]->amount);
        self::assertSame(100, $parts[2]->amount);
    }

    #[Test]
    public function allocateDistributesRemainderOneUnitAtATime(): void
    {
        $money = Money::of(100, Currency::USD);

        $parts = $money->allocate(3);

        self::assertCount(3, $parts);
        self::assertSame(34, $parts[0]->amount);
        self::assertSame(33, $parts[1]->amount);
        self::assertSame(33, $parts[2]->amount);

        // Sum should equal original
        $sum = $parts[0]->amount + $parts[1]->amount + $parts[2]->amount;
        self::assertSame(100, $sum);
    }

    #[Test]
    public function allocateRejectsZeroParts(): void
    {
        $money = Money::of(100, Currency::USD);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('positive');

        (void) $money->allocate(0);
    }

    #[Test]
    public function allocateRejectsNegativeParts(): void
    {
        $money = Money::of(100, Currency::USD);

        $this->expectException(MoneyException::class);

        (void) $money->allocate(-1);
    }

    #[Test]
    public function formatTwoDecimalCurrency(): void
    {
        $money = Money::of(1050, Currency::USD);

        self::assertSame('10.50', $money->format());
    }

    #[Test]
    public function formatZeroDecimalCurrency(): void
    {
        // JPY has 0 minor digits
        $money = Money::of(1000, Currency::JPY);

        self::assertSame('1000', $money->format());
    }

    #[Test]
    public function formatThreeDecimalCurrency(): void
    {
        // BHD has 3 minor digits
        $money = Money::of(1500, Currency::BHD);

        self::assertSame('1.500', $money->format());
    }

    #[Test]
    public function formatSmallAmount(): void
    {
        $money = Money::of(5, Currency::USD);

        self::assertSame('0.05', $money->format());
    }

    #[Test]
    public function isZeroReturnsTrueForZero(): void
    {
        self::assertTrue(Money::of(0, Currency::USD)->isZero());
    }

    #[Test]
    public function isZeroReturnsFalseForNonZero(): void
    {
        self::assertFalse(Money::of(1, Currency::USD)->isZero());
    }

    #[Test]
    public function equalsComparesAmountAndCurrency(): void
    {
        $a = Money::of(100, Currency::USD);
        $b = Money::of(100, Currency::USD);
        $c = Money::of(200, Currency::USD);
        $d = Money::of(100, Currency::EUR);

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
        self::assertFalse($a->equals($d));
    }

    #[Test]
    public function addReturnsNewInstance(): void
    {
        $a = Money::of(100, Currency::USD);
        $b = Money::of(50, Currency::USD);

        $result = $a->add($b);

        self::assertNotSame($a, $result);
        self::assertSame(100, $a->amount);
    }

    #[Test]
    public function addThrowsOnOverflow(): void
    {
        // F21.8: PHP_INT_MAX + 1 silently wraps to PHP_INT_MIN on
        // 64-bit ints. Money MUST refuse rather than report a
        // negative balance.
        $a = Money::of(PHP_INT_MAX, Currency::USD);
        $b = Money::of(1, Currency::USD);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('overflow');

        (void) $a->add($b);
    }

    #[Test]
    public function addAcceptsExactPhpIntMaxResult(): void
    {
        // The boundary case: a + b == PHP_INT_MAX is still valid.
        $a = Money::of(PHP_INT_MAX - 100, Currency::USD);
        $b = Money::of(100, Currency::USD);

        $result = $a->add($b);

        self::assertSame(PHP_INT_MAX, $result->amount);
    }

    #[Test]
    public function multiplyThrowsOnOverflow(): void
    {
        $a = Money::of(PHP_INT_MAX, Currency::USD);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('overflow');

        (void) $a->multiply(2);
    }

    #[Test]
    public function multiplyAcceptsZeroFactor(): void
    {
        // Zero factor must NOT trigger the overflow guard's
        // intdiv-by-zero. The result is always 0.
        $a = Money::of(PHP_INT_MAX, Currency::USD);

        $result = $a->multiply(0);

        self::assertSame(0, $result->amount);
    }

    #[Test]
    public function percentageThrowsOnIntermediateOverflow(): void
    {
        // amount * basisPoints can overflow before the /10000
        // division happens. Verify the guard rejects it instead of
        // silently wrapping.
        $a = Money::of(PHP_INT_MAX, Currency::USD);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageIsOrContains('overflow');

        (void) $a->percentage(20000); // 200% — would multiply, then divide
    }
}
