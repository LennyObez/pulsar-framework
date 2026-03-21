<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Coupon;

use Pulsar\Api\Api;

/**
 * Result of coupon validation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CouponValidationResult
{
    /**
     * @param bool $isValid Whether the coupon is valid
     * @param string $reason Reason for invalidity (empty if valid)
     * @param Coupon|null $coupon The validated coupon (null if not found)
     */
    public function __construct(
        public bool $isValid,
        public string $reason = '',
        public ?Coupon $coupon = null,
    ) {}

    public static function valid(Coupon $coupon): self
    {
        return new self(isValid: true, coupon: $coupon);
    }

    public static function invalid(string $reason): self
    {
        return new self(isValid: false, reason: $reason);
    }
}
