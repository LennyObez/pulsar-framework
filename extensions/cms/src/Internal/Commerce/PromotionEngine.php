<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DiscountResult;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\PromotionType;
use Pulsar\Extension\Cms\Commerce\PromotionValidationResult;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function array_column;
use function count;
use function in_array;
use function intval;
use function min;
use function round;
use function sprintf;
use function usort;

/**
 * Promotion validation, discount calculation, and usage tracking engine.
 */
#[Internal(reason: 'Use PromotionServiceInterface for public API')]
final readonly class PromotionEngine implements PromotionServiceInterface
{
    public function __construct(
        private PromotionRepositoryInterface $promotions,
        private CouponRepositoryInterface $coupons,
        private ConnectionInterface $db,
    ) {}

    /**
     * @param list<array{productId: string, quantity: int, unitPrice: int}> $cartItems
     */
    public function validateCoupon(string $code, array $cartItems, ?string $customerId = null): PromotionValidationResult
    {
        $coupon = $this->coupons->findByCode($code);

        if ($coupon === null) {
            return new PromotionValidationResult(false, null, ['Coupon code not found']);
        }

        if (!$coupon->isAvailable()) {
            return new PromotionValidationResult(false, null, ['Coupon has already been used']);
        }

        $promotion = $this->promotions->findById($coupon->promotionId);

        if ($promotion === null) {
            return new PromotionValidationResult(false, null, ['Associated promotion not found']);
        }

        $errors = [];
        $now = new DateTimeImmutable();

        // Check active
        if (!$promotion->isActive) {
            $errors[] = 'Promotion is not active';
        }

        // Check date range
        if (!$promotion->isWithinDateRange($now)) {
            $errors[] = 'Promotion is outside its valid date range';
        }

        // Check max uses
        if (!$promotion->hasRemainingUses()) {
            $errors[] = 'Promotion has reached its maximum number of uses';
        }

        // Check per-customer limit
        if ($customerId !== null && $promotion->maxUsesPerCustomer !== null) {
            $customerUsage = $this->coupons->countCustomerUsage($promotion->id, $customerId);

            if ($customerUsage >= $promotion->maxUsesPerCustomer) {
                $errors[] = sprintf(
                    'You have already used this promotion %d time(s)',
                    $customerUsage,
                );
            }
        }

        // Check minimum order amount
        if ($promotion->minOrderAmount !== null) {
            $cartTotal = 0;

            foreach ($cartItems as $item) {
                $cartTotal += $item['unitPrice'] * $item['quantity'];
            }

            if ($cartTotal < $promotion->minOrderAmount) {
                $errors[] = sprintf(
                    'Minimum order amount of %d not met (current: %d)',
                    $promotion->minOrderAmount,
                    $cartTotal,
                );
            }
        }

        // Check applicable products/categories
        if ($promotion->applicableProductIds !== []) {
            $cartProductIds = array_column($cartItems, 'productId');
            $hasApplicableProduct = false;

            foreach ($cartProductIds as $pid) {
                if (in_array($pid, $promotion->applicableProductIds, true)) {
                    $hasApplicableProduct = true;

                    break;
                }
            }

            if (!$hasApplicableProduct) {
                $errors[] = 'No eligible products in cart for this promotion';
            }
        }

        if ($errors !== []) {
            return new PromotionValidationResult(false, $promotion, $errors);
        }

        return new PromotionValidationResult(true, $promotion, []);
    }

    /**
     * @param list<array{productId: string, quantity: int, unitPrice: int}> $items
     */
    public function calculateDiscount(Promotion $promotion, array $items): DiscountResult
    {
        return match ($promotion->type) {
            PromotionType::PercentageOff => $this->calculatePercentageOff($promotion, $items),
            PromotionType::FixedAmountOff => $this->calculateFixedAmountOff($promotion, $items),
            PromotionType::FreeShipping => new DiscountResult(0, []),
            PromotionType::BuyXGetY => $this->calculateBuyXGetY($promotion, $items),
        };
    }

    public function incrementUsage(string $promotionId, ?string $customerId = null): void
    {
        $this->promotions->incrementUsage($promotionId);

        if ($customerId !== null) {
            $this->db->execute(
                'INSERT INTO cms_coupon_usages (id, promotion_id, customer_id, used_at) VALUES (:id, :promotion_id, :customer_id, :used_at)',
                [
                    'id' => UuidGenerator::v7(),
                    'promotion_id' => $promotionId,
                    'customer_id' => $customerId,
                    'used_at' => new DateTimeImmutable()->format('c'),
                ],
            );
        }
    }

    /**
     * @param list<array{productId: string, quantity: int, unitPrice: int}> $items
     */
    private function calculatePercentageOff(Promotion $promotion, array $items): DiscountResult
    {
        $itemDiscounts = [];
        $totalDiscount = 0;

        foreach ($items as $item) {
            if (!$this->isItemEligible($promotion, $item['productId'])) {
                continue;
            }

            $lineTotal = $item['unitPrice'] * $item['quantity'];
            $discount = intval(round($lineTotal * $promotion->value / 100));
            $itemDiscounts[$item['productId']] = $discount;
            $totalDiscount += $discount;
        }

        return new DiscountResult($totalDiscount, $itemDiscounts);
    }

    /**
     * @param list<array{productId: string, quantity: int, unitPrice: int}> $items
     */
    private function calculateFixedAmountOff(Promotion $promotion, array $items): DiscountResult
    {
        $eligibleTotal = 0;

        foreach ($items as $item) {
            if ($this->isItemEligible($promotion, $item['productId'])) {
                $eligibleTotal += $item['unitPrice'] * $item['quantity'];
            }
        }

        $totalDiscount = min($promotion->value, $eligibleTotal);

        // Distribute proportionally across eligible items
        $itemDiscounts = [];

        if ($eligibleTotal > 0) {
            foreach ($items as $item) {
                if (!$this->isItemEligible($promotion, $item['productId'])) {
                    continue;
                }

                $lineTotal = $item['unitPrice'] * $item['quantity'];
                $proportion = (float) $lineTotal / (float) $eligibleTotal;
                $itemDiscounts[$item['productId']] = intval(round((float) $totalDiscount * $proportion));
            }
        }

        return new DiscountResult($totalDiscount, $itemDiscounts);
    }

    /**
     * @param list<array{productId: string, quantity: int, unitPrice: int}> $items
     */
    private function calculateBuyXGetY(Promotion $promotion, array $items): DiscountResult
    {
        // Buy X items, get the cheapest one free for each X
        $eligibleItems = [];

        foreach ($items as $item) {
            if (!$this->isItemEligible($promotion, $item['productId'])) {
                continue;
            }

            // Expand quantities into individual unit entries for sorting
            for ($i = 0; $i < $item['quantity']; $i++) {
                $eligibleItems[] = [
                    'productId' => $item['productId'],
                    'unitPrice' => $item['unitPrice'],
                ];
            }
        }

        // Sort by price descending so cheapest items are at the end
        usort($eligibleItems, static fn(array $a, array $b): int => $b['unitPrice'] <=> $a['unitPrice']);

        // The "value" on buy_x_get_y promotions represents X (the group size)
        $groupSize = $promotion->value;

        if ($groupSize <= 1 || count($eligibleItems) < $groupSize) {
            return new DiscountResult(0, []);
        }

        $itemDiscounts = [];
        $totalDiscount = 0;

        // For every group of X items, the cheapest one (last in the sorted group) is free
        $chunks = array_chunk($eligibleItems, $groupSize);

        foreach ($chunks as $chunk) {
            if (count($chunk) < $groupSize) {
                break;
            }

            // The cheapest item in this group
            $freeItem = $chunk[$groupSize - 1];
            $productId = $freeItem['productId'];
            $itemDiscounts[$productId] = ($itemDiscounts[$productId] ?? 0) + $freeItem['unitPrice'];
            $totalDiscount += $freeItem['unitPrice'];
        }

        return new DiscountResult($totalDiscount, $itemDiscounts);
    }

    private function isItemEligible(Promotion $promotion, string $productId): bool
    {
        // Empty list means all products are eligible
        if ($promotion->applicableProductIds === []) {
            return true;
        }

        return in_array($productId, $promotion->applicableProductIds, true);
    }
}
