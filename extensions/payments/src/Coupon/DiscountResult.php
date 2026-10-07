<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Coupon;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Result of applying a discount to an amount.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiscountResult
{
    /**
     * @param Money $originalAmount Amount before discount
     * @param Money $discountAmount Amount of the discount
     * @param Money $finalAmount Amount after discount
     * @param string $couponCode Applied coupon code
     */
    public function __construct(
        public Money $originalAmount,
        public Money $discountAmount,
        public Money $finalAmount,
        public string $couponCode,
    ) {}
}
