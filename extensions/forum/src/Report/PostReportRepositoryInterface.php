<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Report;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Domain\ReportStatus;

/**
 * Repository interface for post reports.
 */
#[Api(since: '1.0.0')]
interface PostReportRepositoryInterface
{
    public function findById(string $id): ?PostReport;

    /**
     * Find a pending report from a specific reporter for a specific post.
     */
    public function findByReporterAndPost(string $reporterId, string $postId): ?PostReport;

    /**
     * Find reports for a specific post.
     *
     * @return list<PostReport>
     */
    public function findByPost(string $postId): array;

    /**
     * Find reports by status with pagination.
     *
     * @return PaginationResult<PostReport>
     */
    public function findByStatus(
        ReportStatus $status,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult;

    /**
     * Count pending reports for a post.
     */
    public function countPendingForPost(string $postId): int;

    public function save(PostReport $report): void;

    public function delete(PostReport $report): void;
}
