<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Users;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for CMS user management.
 *
 * Provides queries over the auth_users table scoped to users
 * who hold CMS roles. This is a read-heavy interface; mutations
 * (role assignment, 2FA reset) go through the Auth module's APIs.
 *
 * @psalm-api Public binding contract; implemented by DbCmsUserRepository
 *            and consumed by admin user-management controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface CmsUserRepositoryInterface
{
    /**
     * Find a CMS user by ID.
     */
    public function findById(string $id): ?CmsUser;

    /**
     * List CMS users with optional filtering.
     *
     * @return PaginationResult<CmsUser>
     */
    public function listUsers(
        ?string $tenantId = null,
        ?string $role = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    /**
     * Update a user's CMS roles.
     *
     * @param list<string> $roles
     */
    public function updateRoles(string $userId, array $roles): void;

    /**
     * Reset a user's two-factor authentication status.
     */
    public function resetTwoFactor(string $userId): void;
}
