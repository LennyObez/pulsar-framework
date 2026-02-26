<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Report;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for forum moderation log entries.
 */
#[Api(since: '1.0.0')]
interface ForumModerationLogRepositoryInterface
{
    public function findById(string $id): ?ForumModerationLog;

    /**
     * Find moderation log entries by the moderator who performed them.
     *
     * @return PaginationResult<ForumModerationLog>
     */
    public function findByModerator(string $moderatorId, int $page = 1, int $perPage = 20): PaginationResult;

    /**
     * Find moderation log entries targeting a specific entity.
     *
     * @return list<ForumModerationLog>
     */
    public function findByTarget(string $targetType, string $targetId): array;

    /**
     * Find recent moderation log entries across the forum.
     *
     * @return PaginationResult<ForumModerationLog>
     */
    public function findRecent(int $page = 1, int $perPage = 20, ?string $tenantId = null): PaginationResult;

    public function save(ForumModerationLog $log): void;
}
