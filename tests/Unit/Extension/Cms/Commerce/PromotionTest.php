<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionType;

#[CoversClass(Promotion::class)]
final class PromotionTest extends TestCase
{
    // ── Promotion types ─────────────────────────────────────────────

    #[Test]
    public function percentageOffType(): void
    {
        $promo = $this->createPromotion(type: PromotionType::PercentageOff, value: 15);

        self::assertSame(PromotionType::PercentageOff, $promo->type);
        self::assertSame(15, $promo->value);
        self::assertSame('Percentage Off', $promo->type->label());
    }

    #[Test]
    public function fixedAmountOffType(): void
    {
        $promo = $this->createPromotion(type: PromotionType::FixedAmountOff, value: 500);

        self::assertSame(PromotionType::FixedAmountOff, $promo->type);
        self::assertSame(500, $promo->value);
        self::assertSame('Fixed Amount Off', $promo->type->label());
    }

    #[Test]
    public function freeShippingType(): void
    {
        $promo = $this->createPromotion(type: PromotionType::FreeShipping, value: 0);

        self::assertSame(PromotionType::FreeShipping, $promo->type);
        self::assertSame('Free Shipping', $promo->type->label());
    }

    #[Test]
    public function buyXGetYType(): void
    {
        $promo = $this->createPromotion(type: PromotionType::BuyXGetY, value: 1);

        self::assertSame(PromotionType::BuyXGetY, $promo->type);
        self::assertSame('Buy X Get Y', $promo->type->label());
    }

    // ── Validation: expired promotion ───────────────────────────────

    #[Test]
    public function expiredPromotionIsOutOfDateRange(): void
    {
        $promo = new Promotion(
            id: 'promo-001',
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
            startsAt: new DateTimeImmutable('-30 days'),
            expiresAt: new DateTimeImmutable('-1 day'),
            isActive: true,
        );

        self::assertFalse($promo->isWithinDateRange());
    }

    // ── Validation: not yet started ─────────────────────────────────

    #[Test]
    public function notYetStartedPromotion(): void
    {
        $promo = new Promotion(
            id: 'promo-002',
            tenantId: null,
            name: 'Future Sale',
            type: PromotionType::PercentageOff,
            value: 20,
            minOrderAmount: null,
            maxUses: null,
            maxUsesPerCustomer: null,
            currentUses: 0,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: new DateTimeImmutable('+1 day'),
            expiresAt: new DateTimeImmutable('+30 days'),
            isActive: true,
        );

        self::assertFalse($promo->isWithinDateRange());
    }

    // ── Validation: within date range ───────────────────────────────

    #[Test]
    public function activePromotionWithinDateRange(): void
    {
        $promo = new Promotion(
            id: 'promo-003',
            tenantId: null,
            name: 'Current Sale',
            type: PromotionType::PercentageOff,
            value: 10,
            minOrderAmount: null,
            maxUses: null,
            maxUsesPerCustomer: null,
            currentUses: 0,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: new DateTimeImmutable('-1 day'),
            expiresAt: new DateTimeImmutable('+30 days'),
            isActive: true,
        );

        self::assertTrue($promo->isWithinDateRange());
    }

    // ── Validation: max uses exceeded ───────────────────────────────

    #[Test]
    public function maxUsesExceeded(): void
    {
        $promo = new Promotion(
            id: 'promo-004',
            tenantId: null,
            name: 'Limited Sale',
            type: PromotionType::FixedAmountOff,
            value: 100,
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

        self::assertFalse($promo->hasRemainingUses());
    }

    #[Test]
    public function hasRemainingUses(): void
    {
        $promo = new Promotion(
            id: 'promo-005',
            tenantId: null,
            name: 'Limited Sale',
            type: PromotionType::FixedAmountOff,
            value: 100,
            minOrderAmount: null,
            maxUses: 10,
            maxUsesPerCustomer: null,
            currentUses: 5,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: null,
            expiresAt: null,
            isActive: true,
        );

        self::assertTrue($promo->hasRemainingUses());
    }

    #[Test]
    public function unlimitedUsesAlwaysHasRemaining(): void
    {
        $promo = new Promotion(
            id: 'promo-006',
            tenantId: null,
            name: 'Unlimited Sale',
            type: PromotionType::PercentageOff,
            value: 5,
            minOrderAmount: null,
            maxUses: null,
            maxUsesPerCustomer: null,
            currentUses: 9999,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: null,
            expiresAt: null,
            isActive: true,
        );

        self::assertTrue($promo->hasRemainingUses());
    }

    // ── Min order amount ────────────────────────────────────────────

    #[Test]
    public function minOrderAmountField(): void
    {
        $promo = $this->createPromotion(minOrderAmount: 5000);

        self::assertSame(5000, $promo->minOrderAmount);
    }

    // ── Applicable products ─────────────────────────────────────────

    #[Test]
    public function applicableProductIds(): void
    {
        $promo = new Promotion(
            id: 'promo-007',
            tenantId: null,
            name: 'Product Specific',
            type: PromotionType::PercentageOff,
            value: 10,
            minOrderAmount: null,
            maxUses: null,
            maxUsesPerCustomer: null,
            currentUses: 0,
            applicableProductIds: ['product-001', 'product-002'],
            applicableCategoryIds: [],
            startsAt: null,
            expiresAt: null,
            isActive: true,
        );

        self::assertSame(['product-001', 'product-002'], $promo->applicableProductIds);
    }

    // ── No date constraints means always valid ──────────────────────

    #[Test]
    public function nullDatesAlwaysWithinRange(): void
    {
        $promo = $this->createPromotion();

        self::assertTrue($promo->isWithinDateRange());
    }

    // ── Promotion type enum values ──────────────────────────────────

    #[Test]
    public function promotionTypeStringValues(): void
    {
        self::assertSame('percentage_off', PromotionType::PercentageOff->value);
        self::assertSame('fixed_amount_off', PromotionType::FixedAmountOff->value);
        self::assertSame('free_shipping', PromotionType::FreeShipping->value);
        self::assertSame('buy_x_get_y', PromotionType::BuyXGetY->value);
    }

    private function createPromotion(
        PromotionType $type = PromotionType::PercentageOff,
        int $value = 10,
        ?int $minOrderAmount = null,
    ): Promotion {
        return new Promotion(
            id: 'promo-test',
            tenantId: null,
            name: 'Test Promotion',
            type: $type,
            value: $value,
            minOrderAmount: $minOrderAmount,
            maxUses: null,
            maxUsesPerCustomer: null,
            currentUses: 0,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: null,
            expiresAt: null,
            isActive: true,
        );
    }
}
