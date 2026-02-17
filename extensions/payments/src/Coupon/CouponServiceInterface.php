<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Coupon;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Service for managing coupons and applying discounts.
 */
#[Api(since: '1.0.0')]
interface CouponServiceInterface
{
    /**
     * Create a new coupon.
     */
    public function create(Coupon $coupon): Coupon;

    /**
     * Find a coupon by code.
     */
    public function findByCode(string $code): ?Coupon;

    /**
     * Find a coupon by ID.
     */
    public function findById(string $id): ?Coupon;

    /**
     * Validate that a coupon can be applied.
     *
     * Checks expiry, redemption limits, and plan applicability.
     */
    public function validate(string $code, string $customerId, ?string $planId = null): CouponValidationResult;

    /**
     * Apply a coupon to calculate the discounted amount.
     *
     * @throws InvalidArgumentException If coupon is invalid
     */
    public function applyDiscount(string $code, Money $originalAmount): DiscountResult;

    /**
     * Record a coupon redemption.
     */
    public function redeem(string $code, string $customerId): void;

    /**
     * Deactivate a coupon (prevent further use).
     */
    public function deactivate(string $id): void;

    /**
     * List all coupons, optionally filtered.
     *
     * @return list<Coupon>
     */
    public function list(bool $activeOnly = true, int $limit = 50, int $offset = 0): array;
}
