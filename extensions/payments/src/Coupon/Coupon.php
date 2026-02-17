<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Coupon;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function in_array;

/**
 * A discount coupon that can be applied to payments or subscriptions.
 */
#[Api(since: '1.0.0')]
final readonly class Coupon
{
    /**
     * @param string $id Unique coupon identifier
     * @param string $code User-facing coupon code (e.g., 'SAVE20')
     * @param DiscountType $discountType Type of discount
     * @param int $discountValue Discount value (basis points for percentage, minor units for fixed)
     * @param string $currency Currency for fixed-amount discounts (ISO 4217)
     * @param int $maxRedemptions Maximum total uses (0 = unlimited)
     * @param int $timesRedeemed Current redemption count
     * @param int $maxRedemptionsPerCustomer Max uses per customer (0 = unlimited)
     * @param bool $appliesToSubscriptions Whether coupon applies to subscription recurring charges
     * @param int $durationMonths For subscriptions: how many months the discount lasts (0 = forever)
     * @param list<string> $applicablePlanIds Restrict to specific plan IDs (empty = all plans)
     */
    public function __construct(
        public string $id,
        public string $code,
        public DiscountType $discountType,
        public int $discountValue,
        public string $currency = 'USD',
        public int $maxRedemptions = 0,
        public int $timesRedeemed = 0,
        public int $maxRedemptionsPerCustomer = 1,
        public bool $appliesToSubscriptions = true,
        public int $durationMonths = 0,
        public array $applicablePlanIds = [],
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}

    /**
     * Check if the coupon is currently valid.
     */
    public function isValid(): bool
    {
        $now = new DateTimeImmutable();

        if ($this->validFrom !== null && $now < $this->validFrom) {
            return false;
        }

        if ($this->validUntil !== null && $now > $this->validUntil) {
            return false;
        }

        if ($this->maxRedemptions > 0 && $this->timesRedeemed >= $this->maxRedemptions) {
            return false;
        }

        return true;
    }

    /**
     * Check if the coupon can be applied to a specific plan.
     */
    public function appliesToPlan(string $planId): bool
    {
        if ($this->applicablePlanIds === []) {
            return true;
        }

        return in_array($planId, $this->applicablePlanIds, true);
    }
}
