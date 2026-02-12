<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Persistence contract for feedback entities.
 *
 * Implementations must support upsert semantics (insert or update on conflict),
 * pagination, filtering by category/status, and a per-user daily count for
 * rate limiting.
 */
#[Api(since: '1.0.0')]
interface FeedbackRepositoryInterface
{
    /**
     * Persist a feedback entity (insert or update).
     */
    public function save(Feedback $feedback): void;

    /**
     * Find a feedback entity by its identifier.
     */
    public function findById(string $id): ?Feedback;

    /**
     * Find feedback submitted by a specific user, paginated.
     *
     * @return PaginationResult<Feedback>
     */
    public function findByUser(string $userId, int $page, int $perPage): PaginationResult;

    /**
     * Find all feedback with optional category and status filters, paginated.
     *
     * @return PaginationResult<Feedback>
     */
    public function findAll(
        int $page,
        int $perPage,
        ?FeedbackCategory $category = null,
        ?FeedbackStatus $status = null,
    ): PaginationResult;

    /**
     * Count feedback submitted by a user today (UTC), for rate limiting.
     *
     * Returns the number of submissions created on the current date,
     * used to enforce a maximum of 10 submissions per user per day.
     */
    public function countByUserToday(string $userId): int;
}
