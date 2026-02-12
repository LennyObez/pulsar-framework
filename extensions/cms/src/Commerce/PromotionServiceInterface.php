<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Service interface for coupon validation, discount calculation, and usage tracking.
 */
#[Api(since: '1.0.0')]
interface PromotionServiceInterface
{
    /**
     * Validate a coupon code against the current cart and customer.
     *
     * @param list<array{productId: string, quantity: int, unitPrice: int, variantId?: string|null}> $cartItems
     */
    public function validateCoupon(string $code, array $cartItems, ?string $customerId = null): PromotionValidationResult;

    /**
     * Calculate the discount for a validated promotion.
     *
     * @param list<array{productId: string, quantity: int, unitPrice: int, variantId?: string|null}> $items
     */
    public function calculateDiscount(Promotion $promotion, array $items): DiscountResult;

    /**
     * Increment usage counters after a promotion is applied to an order.
     */
    public function incrementUsage(string $promotionId, ?string $customerId = null): void;
}
