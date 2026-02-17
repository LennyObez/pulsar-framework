<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Coupon;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Coupon\Coupon;
use Pulsar\Extension\Payments\Coupon\CouponValidationResult;
use Pulsar\Extension\Payments\Coupon\DiscountResult;
use Pulsar\Extension\Payments\Coupon\DiscountType;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

final class CouponTest extends TestCase
{
    #[Test]
    public function isValidReturnsTrueForActiveCoupon(): void
    {
        $coupon = new Coupon(
            id: 'c-1',
            code: 'SAVE20',
            discountType: DiscountType::Percentage,
            discountValue: 2000,
        );

        self::assertTrue($coupon->isValid());
    }

    #[Test]
    public function isValidReturnsFalseWhenExpired(): void
    {
        $coupon = new Coupon(
            id: 'c-2',
            code: 'EXPIRED',
            discountType: DiscountType::Percentage,
            discountValue: 1000,
            validUntil: new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($coupon->isValid());
    }

    #[Test]
    public function isValidReturnsFalseWhenNotYetActive(): void
    {
        $coupon = new Coupon(
            id: 'c-3',
            code: 'FUTURE',
            discountType: DiscountType::Percentage,
            discountValue: 1000,
            validFrom: new DateTimeImmutable('+1 day'),
        );

        self::assertFalse($coupon->isValid());
    }

    #[Test]
    public function isValidReturnsFalseWhenMaxRedemptionsReached(): void
    {
        $coupon = new Coupon(
            id: 'c-4',
            code: 'LIMITED',
            discountType: DiscountType::FixedAmount,
            discountValue: 500,
            maxRedemptions: 10,
            timesRedeemed: 10,
        );

        self::assertFalse($coupon->isValid());
    }

    #[Test]
    public function isValidReturnsTrueWhenUnlimitedRedemptions(): void
    {
        $coupon = new Coupon(
            id: 'c-5',
            code: 'UNLIMITED',
            discountType: DiscountType::Percentage,
            discountValue: 500,
            maxRedemptions: 0,
            timesRedeemed: 1000,
        );

        self::assertTrue($coupon->isValid());
    }

    #[Test]
    public function appliesToPlanReturnsTrueForUnrestricted(): void
    {
        $coupon = new Coupon(
            id: 'c-6',
            code: 'ALL',
            discountType: DiscountType::Percentage,
            discountValue: 500,
            applicablePlanIds: [],
        );

        self::assertTrue($coupon->appliesToPlan('any-plan'));
    }

    #[Test]
    public function appliesToPlanReturnsTrueForMatchingPlan(): void
    {
        $coupon = new Coupon(
            id: 'c-7',
            code: 'PRO',
            discountType: DiscountType::Percentage,
            discountValue: 1000,
            applicablePlanIds: ['pro-monthly', 'pro-annual'],
        );

        self::assertTrue($coupon->appliesToPlan('pro-monthly'));
        self::assertFalse($coupon->appliesToPlan('basic-monthly'));
    }

    #[Test]
    public function discountTypeEnumValues(): void
    {
        self::assertSame('percentage', DiscountType::Percentage->value);
        self::assertSame('fixed_amount', DiscountType::FixedAmount->value);
        self::assertSame('free_trial', DiscountType::FreeTrial->value);
        self::assertCount(3, DiscountType::cases());
    }

    #[Test]
    public function couponValidationResultValid(): void
    {
        $coupon = new Coupon('c-1', 'SAVE20', DiscountType::Percentage, 2000);

        $result = CouponValidationResult::valid($coupon);

        self::assertTrue($result->isValid);
        self::assertSame('', $result->reason);
        self::assertSame($coupon, $result->coupon);
    }

    #[Test]
    public function couponValidationResultInvalid(): void
    {
        $result = CouponValidationResult::invalid('Coupon expired');

        self::assertFalse($result->isValid);
        self::assertSame('Coupon expired', $result->reason);
        self::assertNull($result->coupon);
    }

    #[Test]
    public function discountResultStoresAmounts(): void
    {
        $original = Money::of(10000, Currency::USD);
        $discount = Money::of(2000, Currency::USD);
        $final = Money::of(8000, Currency::USD);

        $result = new DiscountResult(
            originalAmount: $original,
            discountAmount: $discount,
            finalAmount: $final,
            couponCode: 'SAVE20',
        );

        self::assertSame(10000, $result->originalAmount->amount);
        self::assertSame(2000, $result->discountAmount->amount);
        self::assertSame(8000, $result->finalAmount->amount);
        self::assertSame('SAVE20', $result->couponCode);
    }
}
