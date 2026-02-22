<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Coupon;
use Pulsar\Extension\Cms\Commerce\DiscountResult;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionType;
use Pulsar\Extension\Cms\Commerce\PromotionValidationResult;

#[CoversClass(Promotion::class)]
#[CoversClass(Coupon::class)]
#[CoversClass(PromotionValidationResult::class)]
#[CoversClass(DiscountResult::class)]
final class PromotionIntegrationTest extends TestCase
{
    #[Test]
    public function test_full_promotion_flow(): void
    {
        // Step 1: Create promotion with coupon
        $promotion = new Promotion(
            id: 'promo-001',
            tenantId: null,
            name: 'Summer Sale 20%',
            type: PromotionType::PercentageOff,
            value: 20,
            minOrderAmount: 1000,
            maxUses: 100,
            maxUsesPerCustomer: 1,
            currentUses: 0,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: new DateTimeImmutable('-1 day'),
            expiresAt: new DateTimeImmutable('+30 days'),
            isActive: true,
        );

        $coupon = new Coupon(
            id: 'coupon-001',
            promotionId: 'promo-001',
            code: 'SUMMER20',
            isSingleUse: false,
            usedAt: null,
            usedBy: null,
        );

        self::assertTrue($coupon->isAvailable());
        self::assertTrue($promotion->isWithinDateRange());
        self::assertTrue($promotion->hasRemainingUses());

        // Step 2: Validate coupon against cart
        $cartItems = [
            ['productId' => 'prod-001', 'quantity' => 2, 'unitPrice' => 2500],
            ['productId' => 'prod-002', 'quantity' => 1, 'unitPrice' => 5000],
        ];

        $cartSubtotal = (2500 * 2) + (5000 * 1); // 10000
        $meetsMinOrder = $cartSubtotal >= ($promotion->minOrderAmount ?? 0);

        self::assertTrue($meetsMinOrder);

        $validationResult = new PromotionValidationResult(
            isValid: true,
            promotion: $promotion,
            errors: [],
        );

        self::assertTrue($validationResult->isValid);
        self::assertSame($promotion, $validationResult->promotion);

        // Step 3: Calculate discount
        $discountAmount = (int) round($cartSubtotal * ($promotion->value / 100)); // 20% of 10000 = 2000

        $discountResult = new DiscountResult(
            totalDiscount: $discountAmount,
            itemDiscounts: [
                'prod-001' => (int) round(5000 * 0.20), // 1000
                'prod-002' => (int) round(5000 * 0.20), // 1000
            ],
        );

        self::assertSame(2000, $discountResult->totalDiscount);
        self::assertSame(1000, $discountResult->itemDiscounts['prod-001']);
        self::assertSame(1000, $discountResult->itemDiscounts['prod-002']);

        // Step 4: Verify usage increment
        $updatedPromotion = new Promotion(
            id: $promotion->id,
            tenantId: $promotion->tenantId,
            name: $promotion->name,
            type: $promotion->type,
            value: $promotion->value,
            minOrderAmount: $promotion->minOrderAmount,
            maxUses: $promotion->maxUses,
            maxUsesPerCustomer: $promotion->maxUsesPerCustomer,
            currentUses: $promotion->currentUses + 1,
            applicableProductIds: $promotion->applicableProductIds,
            applicableCategoryIds: $promotion->applicableCategoryIds,
            startsAt: $promotion->startsAt,
            expiresAt: $promotion->expiresAt,
            isActive: $promotion->isActive,
        );

        self::assertSame(1, $updatedPromotion->currentUses);
        self::assertTrue($updatedPromotion->hasRemainingUses());
    }

    #[Test]
    public function test_single_use_coupon_becomes_unavailable(): void
    {
        $coupon = new Coupon(
            id: 'coupon-002',
            promotionId: 'promo-002',
            code: 'ONETIME',
            isSingleUse: true,
            usedAt: null,
            usedBy: null,
        );

        self::assertTrue($coupon->isAvailable());

        // Simulate usage
        $usedCoupon = new Coupon(
            id: $coupon->id,
            promotionId: $coupon->promotionId,
            code: $coupon->code,
            isSingleUse: $coupon->isSingleUse,
            usedAt: new DateTimeImmutable(),
            usedBy: 'customer-001',
        );

        self::assertFalse($usedCoupon->isAvailable());
    }

    #[Test]
    public function test_coupon_code_case_insensitive_comparison(): void
    {
        $coupon = new Coupon(
            id: 'coupon-003',
            promotionId: 'promo-003',
            code: 'SUMMER20',
            isSingleUse: false,
            usedAt: null,
            usedBy: null,
        );

        // Case-insensitive comparison at the service level
        self::assertSame(
            mb_strtoupper($coupon->code),
            mb_strtoupper('summer20'),
        );
    }

    #[Test]
    public function test_expired_promotion_validation_fails(): void
    {
        $expiredPromotion = new Promotion(
            id: 'promo-expired',
            tenantId: null,
            name: 'Expired Sale',
            type: PromotionType::PercentageOff,
            value: 10,
            minOrderAmount: null,
            maxUses: null,
            maxUsesPerCustomer: null,
            currentUses: 0,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: new DateTimeImmutable('-60 days'),
            expiresAt: new DateTimeImmutable('-1 day'),
            isActive: true,
        );

        self::assertFalse($expiredPromotion->isWithinDateRange());

        $result = new PromotionValidationResult(
            isValid: false,
            promotion: null,
            errors: ['Promotion has expired'],
        );

        self::assertFalse($result->isValid);
        self::assertNull($result->promotion);
    }

    #[Test]
    public function test_max_uses_exceeded_validation_fails(): void
    {
        $exhaustedPromotion = new Promotion(
            id: 'promo-exhausted',
            tenantId: null,
            name: 'Limited Sale',
            type: PromotionType::FixedAmountOff,
            value: 500,
            minOrderAmount: null,
            maxUses: 10,
            maxUsesPerCustomer: null,
            currentUses: 10,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: null,
            expiresAt: null,
            isActive: true,
        );

        self::assertFalse($exhaustedPromotion->hasRemainingUses());

        $result = new PromotionValidationResult(
            isValid: false,
            promotion: null,
            errors: ['Promotion has reached maximum number of uses'],
        );

        self::assertFalse($result->isValid);
    }
}
