<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\RoundingMode;

#[CoversClass(Money::class)]
#[CoversClass(RoundingMode::class)]
final class MoneyRoundingTest extends TestCase
{
    #[Test]
    public function percentageWithHalfDown(): void
    {
        // 50% of 333 = 166.5 → HalfDown rounds to 166
        $money = Money::of(333, Currency::USD);
        $result = $money->percentage(5000, RoundingMode::HalfDown);

        self::assertSame(166, $result->amount);
    }

    #[Test]
    public function percentageWithHalfEven(): void
    {
        // 50% of 333 = 166.5 → HalfEven rounds to 166 (even)
        $money = Money::of(333, Currency::USD);
        $result = $money->percentage(5000, RoundingMode::HalfEven);

        self::assertSame(166, $result->amount);
    }

    #[Test]
    public function percentageWithCeiling(): void
    {
        // 50% of 333 = 166.5 → Ceiling rounds to 167
        $money = Money::of(333, Currency::USD);
        $result = $money->percentage(5000, RoundingMode::Ceiling);

        self::assertSame(167, $result->amount);
    }

    #[Test]
    public function percentageWithCeilingOnExactValue(): void
    {
        // 50% of 200 = 100.0 → Ceiling is still 100
        $money = Money::of(200, Currency::USD);
        $result = $money->percentage(5000, RoundingMode::Ceiling);

        self::assertSame(100, $result->amount);
    }

    #[Test]
    public function percentageWithFloorOnExactValue(): void
    {
        // 25% of 1000 = 250.0 → Floor is 250
        $money = Money::of(1000, Currency::USD);
        $result = $money->percentage(2500, RoundingMode::Floor);

        self::assertSame(250, $result->amount);
    }

    #[Test]
    public function percentageDefaultIsHalfUp(): void
    {
        // 50% of 333 = 166.5 → default HalfUp rounds to 167
        $money = Money::of(333, Currency::USD);
        $result = $money->percentage(5000);

        self::assertSame(167, $result->amount);
    }

    #[Test]
    public function roundingModeCaseCount(): void
    {
        self::assertCount(5, RoundingMode::cases());
    }
}
