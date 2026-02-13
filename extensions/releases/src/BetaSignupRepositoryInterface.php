<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Persistence contract for beta signup entities.
 *
 * Implementations must support upsert semantics, email-based lookup,
 * pagination, and daily rate-limit counting by email.
 */
#[Api(since: '1.0.0')]
interface BetaSignupRepositoryInterface
{
    /**
     * Persist a beta signup entity (insert or update).
     */
    public function save(BetaSignup $signup): void;

    /**
     * Find a beta signup by email address.
     */
    public function findByEmail(string $email): ?BetaSignup;

    /**
     * Find all beta signups, paginated.
     *
     * @return PaginationResult<BetaSignup>
     */
    public function findAll(int $page, int $perPage): PaginationResult;

    /**
     * Count signups from a specific email today (UTC), for rate limiting.
     *
     * Returns the number of signups created on the current UTC date,
     * used to enforce a maximum number of signup attempts per email per day.
     */
    public function countByEmailToday(string $email): int;
}
