<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for coupon persistence.
 * @api
 */
#[Api(since: '1.0.0')]
interface CouponRepositoryInterface
{
    /**
     * Find a coupon by its code (case-insensitive).
     */
    public function findByCode(string $code): ?Coupon;

    public function save(Coupon $coupon): void;

    /**
     * Mark a coupon as used by a customer.
     */
    public function markUsed(string $couponId, string $customerId): void;

    /**
     * Count how many times a customer has used coupons for a given promotion.
     */
    public function countCustomerUsage(string $promotionId, string $customerId): int;
}
