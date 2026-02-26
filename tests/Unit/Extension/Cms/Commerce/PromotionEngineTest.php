<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DiscountResult;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionType;
use Pulsar\Extension\Cms\Commerce\PromotionValidationResult;
use Pulsar\Extension\Cms\Internal\Commerce\PromotionEngine;

#[CoversClass(PromotionEngine::class)]
#[CoversClass(DiscountResult::class)]
#[CoversClass(PromotionValidationResult::class)]
final class PromotionEngineTest extends TestCase
{
    private PromotionEngine $engine;
    private PromotionRepositoryInterface $promotions;
    private CouponRepositoryInterface $coupons;

    protected function setUp(): void
    {
        $this->promotions = $this->createStub(PromotionRepositoryInterface::class);
        $this->coupons = $this->createStub(CouponRepositoryInterface::class);
        $db = $this->createStub(ConnectionInterface::class);
        $this->engine = new PromotionEngine($this->promotions, $this->coupons, $db);
    }

    // --- calculateDiscount: PercentageOff ---

    #[Test]
    public function percentageOffAppliesToAllItems(): void
    {
        $promotion = $this->buildPromotion(PromotionType::PercentageOff, value: 10);
        $items = [
            ['productId' => 'p1', 'quantity' => 2, 'unitPrice' => 1000],
            ['productId' => 'p2', 'quantity' => 1, 'unitPrice' => 5000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        // p1: 2 * 1000 = 2000, 10% = 200
        // p2: 1 * 5000 = 5000, 10% = 500
        self::assertSame(700, $result->totalDiscount);
        self::assertSame(200, $result->itemDiscounts['p1']);
        self::assertSame(500, $result->itemDiscounts['p2']);
    }

    #[Test]
    public function percentageOffAppliesToEligibleProductsOnly(): void
    {
        $promotion = $this->buildPromotion(
            PromotionType::PercentageOff,
            value: 50,
            applicableProductIds: ['p1'],
        );
        $items = [
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 2000],
            ['productId' => 'p2', 'quantity' => 1, 'unitPrice' => 3000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        self::assertSame(1000, $result->totalDiscount);
        self::assertArrayHasKey('p1', $result->itemDiscounts);
        self::assertArrayNotHasKey('p2', $result->itemDiscounts);
    }

    // --- calculateDiscount: FixedAmountOff ---

    #[Test]
    public function fixedAmountOffDistributesProportionally(): void
    {
        $promotion = $this->buildPromotion(PromotionType::FixedAmountOff, value: 1000);
        $items = [
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 3000],
            ['productId' => 'p2', 'quantity' => 1, 'unitPrice' => 7000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        self::assertSame(1000, $result->totalDiscount);
        // p1 gets 30% of 1000 = 300, p2 gets 70% of 1000 = 700
        self::assertSame(300, $result->itemDiscounts['p1']);
        self::assertSame(700, $result->itemDiscounts['p2']);
    }

    #[Test]
    public function fixedAmountOffCappedAtEligibleTotal(): void
    {
        $promotion = $this->buildPromotion(PromotionType::FixedAmountOff, value: 50000);
        $items = [
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 1000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        // Discount cannot exceed the eligible total of 1000
        self::assertSame(1000, $result->totalDiscount);
    }

    // --- calculateDiscount: FreeShipping ---

    #[Test]
    public function freeShippingReturnsZeroDiscount(): void
    {
        $promotion = $this->buildPromotion(PromotionType::FreeShipping, value: 0);
        $items = [
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 5000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        self::assertSame(0, $result->totalDiscount);
        self::assertSame([], $result->itemDiscounts);
    }

    // --- calculateDiscount: BuyXGetY ---

    #[Test]
    public function buyXGetYFreesCheapestInGroup(): void
    {
        // Buy 3 get cheapest free (value = 3 = group size)
        $promotion = $this->buildPromotion(PromotionType::BuyXGetY, value: 3);
        $items = [
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 5000],
            ['productId' => 'p2', 'quantity' => 1, 'unitPrice' => 3000],
            ['productId' => 'p3', 'quantity' => 1, 'unitPrice' => 1000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        // The cheapest in the group of 3 (p3 at 1000) is free
        self::assertSame(1000, $result->totalDiscount);
        self::assertSame(1000, $result->itemDiscounts['p3']);
    }

    #[Test]
    public function buyXGetYNotEnoughItems(): void
    {
        $promotion = $this->buildPromotion(PromotionType::BuyXGetY, value: 3);
        $items = [
            ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 5000],
            ['productId' => 'p2', 'quantity' => 1, 'unitPrice' => 3000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        self::assertSame(0, $result->totalDiscount);
    }

    #[Test]
    public function buyXGetYWithMultipleGroups(): void
    {
        // Buy 2 get cheapest free
        $promotion = $this->buildPromotion(PromotionType::BuyXGetY, value: 2);
        $items = [
            ['productId' => 'p1', 'quantity' => 4, 'unitPrice' => 1000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        // 4 items in groups of 2: [1000, 1000] free=1000, [1000, 1000] free=1000
        self::assertSame(2000, $result->totalDiscount);
    }

    #[Test]
    public function buyXGetYGroupSizeOne(): void
    {
        // groupSize=1 is degenerate — should return 0
        $promotion = $this->buildPromotion(PromotionType::BuyXGetY, value: 1);
        $items = [
            ['productId' => 'p1', 'quantity' => 3, 'unitPrice' => 1000],
        ];

        $result = $this->engine->calculateDiscount($promotion, $items);

        self::assertSame(0, $result->totalDiscount);
    }

    // --- DiscountResult ---

    #[Test]
    public function discountResultConstruction(): void
    {
        $result = new DiscountResult(500, ['p1' => 300, 'p2' => 200]);

        self::assertSame(500, $result->totalDiscount);
        self::assertSame(300, $result->itemDiscounts['p1']);
        self::assertSame(200, $result->itemDiscounts['p2']);
    }

    // --- PromotionValidationResult ---

    #[Test]
    public function validResultHasNoErrors(): void
    {
        $promotion = $this->buildPromotion(PromotionType::PercentageOff, value: 10);
        $result = new PromotionValidationResult(true, $promotion, []);

        self::assertTrue($result->isValid);
        self::assertSame($promotion, $result->promotion);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function invalidResultCarriesErrors(): void
    {
        $result = new PromotionValidationResult(false, null, ['Code not found']);

        self::assertFalse($result->isValid);
        self::assertNull($result->promotion);
        self::assertSame(['Code not found'], $result->errors);
    }

    /**
     * @param list<string> $applicableProductIds
     */
    private function buildPromotion(
        PromotionType $type,
        int $value,
        array $applicableProductIds = [],
    ): Promotion {
        return new Promotion(
            id: 'promo-1',
            tenantId: null,
            name: 'Test Promo',
            type: $type,
            value: $value,
            minOrderAmount: null,
            maxUses: null,
            maxUsesPerCustomer: null,
            currentUses: 0,
            applicableProductIds: $applicableProductIds,
            applicableCategoryIds: [],
            startsAt: null,
            expiresAt: null,
            isActive: true,
        );
    }
}
