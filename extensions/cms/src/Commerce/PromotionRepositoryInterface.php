<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for Promotion aggregate management.
 */
#[Api(since: '1.0.0')]
interface PromotionRepositoryInterface
{
    public function findById(string $id): ?Promotion;

    /**
     * Find all currently active promotions, optionally scoped to a tenant.
     *
     * @return list<Promotion>
     */
    public function findActive(?string $tenantId = null): array;

    /**
     * Resolve a promotion through its associated coupon code.
     */
    public function findByCouponCode(string $code): ?Promotion;

    public function save(Promotion $promotion): void;

    /**
     * Atomically increment the usage counter for a promotion.
     */
    public function incrementUsage(string $promotionId): void;
}
