<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Badge;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\Badge;

/**
 * Badge service — evaluate, award, and revoke achievement badges.
 */
#[Api(since: '1.0.0')]
interface BadgeServiceInterface
{
    /**
     * Evaluate whether a user meets the criteria for a badge.
     */
    public function evaluate(string $userId, Badge $badge): bool;

    /**
     * Award a badge to a user. Returns null if the user already has it (idempotent).
     */
    public function award(string $userId, Badge $badge, ?string $tenantId = null): ?UserBadge;

    /**
     * Revoke a badge from a user.
     */
    public function revoke(string $userId, Badge $badge): void;

    /**
     * @return list<UserBadge>
     */
    public function getUserBadges(string $userId): array;

    /**
     * Check if a user has a specific badge.
     */
    public function hasBadge(string $userId, Badge $badge): bool;
}
