<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionType;

#[CoversClass(Promotion::class)]
final class PromotionTest extends TestCase
{
    private function createPromotion(
        ?int $maxUses = null,
        int $currentUses = 0,
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $expiresAt = null,
        bool $isActive = true,
    ): Promotion {
        return new Promotion(
            id: 'promo-1',
            tenantId: null,
            name: 'Test Promotion',
            type: PromotionType::PercentageOff,
            value: 10,
            minOrderAmount: 5000,
            maxUses: $maxUses,
            maxUsesPerCustomer: 1,
            currentUses: $currentUses,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            isActive: $isActive,
        );
    }

    #[Test]
    public function hasRemainingUses_returns_true_when_no_max(): void
    {
        $promo = $this->createPromotion(maxUses: null, currentUses: 999);

        self::assertTrue($promo->hasRemainingUses());
    }

    #[Test]
    public function hasRemainingUses_returns_true_when_under_limit(): void
    {
        $promo = $this->createPromotion(maxUses: 100, currentUses: 50);

        self::assertTrue($promo->hasRemainingUses());
    }

    #[Test]
    public function hasRemainingUses_returns_false_when_at_limit(): void
    {
        $promo = $this->createPromotion(maxUses: 100, currentUses: 100);

        self::assertFalse($promo->hasRemainingUses());
    }

    #[Test]
    public function hasRemainingUses_returns_false_when_over_limit(): void
    {
        $promo = $this->createPromotion(maxUses: 100, currentUses: 101);

        self::assertFalse($promo->hasRemainingUses());
    }

    #[Test]
    public function isWithinDateRange_returns_true_when_no_dates(): void
    {
        $promo = $this->createPromotion();

        self::assertTrue($promo->isWithinDateRange());
    }

    #[Test]
    public function isWithinDateRange_returns_false_before_start(): void
    {
        $future = new DateTimeImmutable('+1 day');
        $promo = $this->createPromotion(startsAt: $future);

        self::assertFalse($promo->isWithinDateRange());
    }

    #[Test]
    public function isWithinDateRange_returns_false_after_expiry(): void
    {
        $past = new DateTimeImmutable('-1 day');
        $promo = $this->createPromotion(expiresAt: $past);

        self::assertFalse($promo->isWithinDateRange());
    }

    #[Test]
    public function isWithinDateRange_returns_true_within_window(): void
    {
        $past = new DateTimeImmutable('-1 day');
        $future = new DateTimeImmutable('+1 day');
        $promo = $this->createPromotion(startsAt: $past, expiresAt: $future);

        self::assertTrue($promo->isWithinDateRange());
    }

    #[Test]
    public function isWithinDateRange_accepts_custom_now(): void
    {
        $startsAt = new DateTimeImmutable('2025-01-01');
        $expiresAt = new DateTimeImmutable('2025-12-31');
        $promo = $this->createPromotion(startsAt: $startsAt, expiresAt: $expiresAt);

        $within = new DateTimeImmutable('2025-06-15');
        self::assertTrue($promo->isWithinDateRange($within));

        $before = new DateTimeImmutable('2024-06-15');
        self::assertFalse($promo->isWithinDateRange($before));

        $after = new DateTimeImmutable('2026-06-15');
        self::assertFalse($promo->isWithinDateRange($after));
    }
}
