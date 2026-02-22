<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Promotional discount rule with usage limits and scheduling.
 */
#[Api(since: '1.0.0')]
final readonly class Promotion
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $name Display name for the promotion
     * @param PromotionType $type Type of discount applied
     * @param int $value Discount value (percentage points or minor currency units)
     * @param int|null $minOrderAmount Minimum order subtotal in minor currency units
     * @param int|null $maxUses Maximum total redemptions allowed
     * @param int|null $maxUsesPerCustomer Maximum redemptions per customer
     * @param int $currentUses Number of times this promotion has been redeemed
     * @param list<string> $applicableProductIds UUIDv7 list of eligible products (empty = all)
     * @param list<string> $applicableCategoryIds Category identifiers for eligibility (empty = all)
     * @param DateTimeImmutable|null $startsAt When the promotion becomes active
     * @param DateTimeImmutable|null $expiresAt When the promotion expires
     * @param bool $isActive Whether the promotion is currently enabled
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $name,
        public PromotionType $type,
        public int $value,
        public ?int $minOrderAmount,
        public ?int $maxUses,
        public ?int $maxUsesPerCustomer,
        public int $currentUses,
        public array $applicableProductIds,
        public array $applicableCategoryIds,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $expiresAt,
        public bool $isActive,
    ) {}

    /**
     * Whether the promotion has remaining uses.
     */
    public function hasRemainingUses(): bool
    {
        if ($this->maxUses === null) {
            return true;
        }

        return $this->currentUses < $this->maxUses;
    }

    /**
     * Whether the promotion is within its scheduled date range.
     */
    public function isWithinDateRange(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        if ($this->startsAt !== null && $now < $this->startsAt) {
            return false;
        }

        if ($this->expiresAt !== null && $now > $this->expiresAt) {
            return false;
        }

        return true;
    }
}
