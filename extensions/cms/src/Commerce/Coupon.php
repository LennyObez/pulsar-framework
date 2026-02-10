<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A redeemable coupon code linked to a promotion.
 */
#[Api(since: '1.0.0')]
final readonly class Coupon
{
    /**
     * @param string $id UUIDv7
     * @param string $promotionId UUIDv7 of the associated promotion
     * @param string $code Unique coupon code entered at checkout
     * @param bool $isSingleUse Whether this coupon can only be used once
     * @param DateTimeImmutable|null $usedAt When the coupon was redeemed
     * @param string|null $usedBy UUIDv7 of the customer who redeemed this coupon
     */
    public function __construct(
        public string $id,
        public string $promotionId,
        public string $code,
        public bool $isSingleUse,
        public ?DateTimeImmutable $usedAt,
        public ?string $usedBy,
    ) {}

    /**
     * Whether this coupon is still available for use.
     */
    public function isAvailable(): bool
    {
        if (!$this->isSingleUse) {
            return true;
        }

        return $this->usedAt === null;
    }
}
