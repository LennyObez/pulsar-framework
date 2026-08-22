<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentTerms;

final class PaymentTermsTest extends TestCase
{
    #[Test]
    public function basicConstructionWithNetDaysOnly(): void
    {
        $terms = new PaymentTerms(netDays: 30);

        self::assertSame(30, $terms->netDays);
        self::assertNull($terms->earlyDiscountPercent);
        self::assertNull($terms->earlyDiscountDays);
        self::assertNull($terms->lateInterestPercent);
        self::assertNull($terms->paymentMeansCode);
        self::assertNull($terms->note);
    }

    #[Test]
    public function fullConstructionWithAllParams(): void
    {
        $terms = new PaymentTerms(
            netDays: 30,
            earlyDiscountPercent: 200,
            earlyDiscountDays: 10,
            lateInterestPercent: 800,
            paymentMeansCode: '58',
            note: '2% discount if paid within 10 days',
        );

        self::assertSame(30, $terms->netDays);
        self::assertSame(200, $terms->earlyDiscountPercent);
        self::assertSame(10, $terms->earlyDiscountDays);
        self::assertSame(800, $terms->lateInterestPercent);
        self::assertSame('58', $terms->paymentMeansCode);
        self::assertSame('2% discount if paid within 10 days', $terms->note);
    }

    #[Test]
    public function calculateEarlyDiscountReturnsCorrectAmount(): void
    {
        $terms = new PaymentTerms(
            netDays: 30,
            earlyDiscountPercent: 200, // 2%
            earlyDiscountDays: 10,
        );

        $total = Money::of(100000, Currency::EUR); // 1000.00 EUR
        $discount = $terms->calculateEarlyDiscount($total);

        self::assertSame(2000, $discount->amount); // 20.00 EUR
        self::assertSame(Currency::EUR, $discount->currency);
    }

    #[Test]
    public function calculateEarlyDiscountReturnsZeroWhenNotConfigured(): void
    {
        $terms = new PaymentTerms(netDays: 30);
        $total = Money::of(100000, Currency::EUR);
        $discount = $terms->calculateEarlyDiscount($total);

        self::assertTrue($discount->isZero());
    }

    #[Test]
    public function calculateEarlyDiscountReturnsZeroForZeroPercent(): void
    {
        $terms = new PaymentTerms(
            netDays: 30,
            earlyDiscountPercent: 0,
            earlyDiscountDays: 10,
        );

        $discount = $terms->calculateEarlyDiscount(Money::of(50000, Currency::USD));
        self::assertTrue($discount->isZero());
    }

    #[Test]
    #[DataProvider('lateInterestProvider')]
    public function calculateLateInterestForVariousOverduePeriods(
        int $totalMinor,
        int $rateBp,
        int $overdueDays,
        int $expectedMinor,
    ): void {
        $terms = new PaymentTerms(
            netDays: 30,
            lateInterestPercent: $rateBp,
        );

        $total = Money::of($totalMinor, Currency::EUR);
        $interest = $terms->calculateLateInterest($total, $overdueDays);

        self::assertSame($expectedMinor, $interest->amount);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function lateInterestProvider(): iterable
    {
        // total cents, rate BP, overdue days, expected interest cents
        // Formula: (total * rateBp / 10000) * (days / 365) = annual_interest * days/365
        // 100000 * 800/10000 = 8000 annual; 8000 * 30/365 = 657.53 → 658 (rounded)
        yield '30 days overdue at 8%' => [100000, 800, 30, 658];
        // 100000 * 800/10000 = 8000 annual; 8000 * 365/365 = 8000
        yield 'full year overdue at 8%' => [100000, 800, 365, 8000];
        // 50000 * 1200/10000 = 6000 annual; 6000 * 90/365 = 1479.45 → 1479
        yield '90 days overdue at 12%' => [50000, 1200, 90, 1479];
    }

    #[Test]
    public function calculateLateInterestReturnsZeroWhenNotConfigured(): void
    {
        $terms = new PaymentTerms(netDays: 30);
        $interest = $terms->calculateLateInterest(Money::of(100000, Currency::EUR), 30);

        self::assertTrue($interest->isZero());
    }

    #[Test]
    public function calculateLateInterestReturnsZeroForZeroDays(): void
    {
        $terms = new PaymentTerms(netDays: 30, lateInterestPercent: 800);
        $interest = $terms->calculateLateInterest(Money::of(100000, Currency::EUR), 0);

        self::assertTrue($interest->isZero());
    }

    #[Test]
    public function calculateLateInterestReturnsZeroForNegativeDays(): void
    {
        $terms = new PaymentTerms(netDays: 30, lateInterestPercent: 800);
        $interest = $terms->calculateLateInterest(Money::of(100000, Currency::EUR), -5);

        self::assertTrue($interest->isZero());
    }

    #[Test]
    public function hasEarlyDiscountRequiresBothPercentAndDays(): void
    {
        $noDiscount = new PaymentTerms(netDays: 30);
        self::assertFalse($noDiscount->hasEarlyDiscount());

        $percentOnly = new PaymentTerms(netDays: 30, earlyDiscountPercent: 200);
        self::assertFalse($percentOnly->hasEarlyDiscount());

        $daysOnly = new PaymentTerms(netDays: 30, earlyDiscountDays: 10);
        self::assertFalse($daysOnly->hasEarlyDiscount());

        $both = new PaymentTerms(netDays: 30, earlyDiscountPercent: 200, earlyDiscountDays: 10);
        self::assertTrue($both->hasEarlyDiscount());

        $zeroPercent = new PaymentTerms(netDays: 30, earlyDiscountPercent: 0, earlyDiscountDays: 10);
        self::assertFalse($zeroPercent->hasEarlyDiscount());

        $zeroDays = new PaymentTerms(netDays: 30, earlyDiscountPercent: 200, earlyDiscountDays: 0);
        self::assertFalse($zeroDays->hasEarlyDiscount());
    }

    #[Test]
    public function hasLateInterest(): void
    {
        $noInterest = new PaymentTerms(netDays: 30);
        self::assertFalse($noInterest->hasLateInterest());

        $zeroInterest = new PaymentTerms(netDays: 30, lateInterestPercent: 0);
        self::assertFalse($zeroInterest->hasLateInterest());

        $withInterest = new PaymentTerms(netDays: 30, lateInterestPercent: 800);
        self::assertTrue($withInterest->hasLateInterest());
    }
}
