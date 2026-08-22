<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Profile;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for forum profiles.
 * @api
 */
#[Api(since: '1.0.0')]
interface ForumProfileRepositoryInterface
{
    public function findById(string $id): ?ForumProfile;

    /**
     * Find a forum profile by user ID.
     */
    public function findByUser(string $userId, ?string $tenantId = null): ?ForumProfile;

    /**
     * Find top contributors ordered by reputation score descending.
     *
     * @return PaginationResult<ForumProfile>
     */
    public function findTopContributors(
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult;

    /**
     * @note The entity object is stale after this call: the database fields are updated server-side.
     *       Re-fetch via findByUser() if you need the updated version.
     */
    public function save(ForumProfile $profile): void;

    public function delete(ForumProfile $profile): void;

    /**
     * Atomically increment the reputation score for a user's profile.
     */
    public function incrementReputation(string $userId, int $delta, ?string $tenantId = null): void;

    /**
     * Atomically increment the post count for a user's profile.
     */
    public function incrementPostCount(string $userId, ?string $tenantId = null, int $delta = 1): void;

    /**
     * Atomically increment the thread count for a user's profile.
     *
     * @param int $delta Positive to increment, negative to decrement (floors at 0)
     */
    public function incrementThreadCount(string $userId, ?string $tenantId = null, int $delta = 1): void;

    /**
     * Clear the ban flag and related fields for a user whose ban has expired.
     */
    public function clearBanFlag(string $userId, ?string $tenantId = null): void;
}
