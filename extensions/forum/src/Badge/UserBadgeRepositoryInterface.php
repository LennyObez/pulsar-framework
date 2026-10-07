<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Badge;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\Badge;

/**
 * Repository interface for user badges.
 * @api
 */
#[Api(since: '1.0.0')]
interface UserBadgeRepositoryInterface
{
    public function findById(string $id): ?UserBadge;

    /**
     * Find all badges awarded to a user.
     *
     * @return list<UserBadge>
     */
    public function findByUser(string $userId, ?string $tenantId = null): array;

    /**
     * Check if a user has a specific badge.
     */
    public function hasBadge(string $userId, Badge $badge, ?string $tenantId = null): bool;

    public function save(UserBadge $userBadge): void;

    public function delete(UserBadge $userBadge): void;
}
