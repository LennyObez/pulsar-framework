<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Badge;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\Badge;

/**
 * Records a badge awarded to a user.
 *
 * Unique per (tenant, user, badge) — enforced at the repository/DB level.
 */
#[Api(since: '1.0.0')]
final readonly class UserBadge
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $userId UUIDv7 FK auth_users
     * @param Badge $badge The badge type
     * @param DateTimeImmutable $awardedAt When the badge was awarded
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $userId,
        public Badge $badge,
        public DateTimeImmutable $awardedAt,
    ) {}

    /**
     * Award a badge to a user.
     */
    public static function award(
        string $id,
        string $userId,
        Badge $badge,
        ?string $tenantId = null,
    ): self {
        return new self(
            id: $id,
            tenantId: $tenantId,
            userId: $userId,
            badge: $badge,
            awardedAt: new DateTimeImmutable(),
        );
    }
}
