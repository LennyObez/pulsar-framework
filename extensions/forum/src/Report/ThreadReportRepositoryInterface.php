<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Report;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Domain\ReportStatus;

/**
 * Repository interface for thread reports.
 * @api
 */
#[Api(since: '1.0.0')]
interface ThreadReportRepositoryInterface
{
    public function findById(string $id): ?ThreadReport;

    /**
     * Find a pending report from a specific reporter for a specific thread.
     */
    public function findByReporterAndThread(string $reporterId, string $threadId): ?ThreadReport;

    /**
     * Find reports for a specific thread.
     *
     * @return list<ThreadReport>
     */
    public function findByThread(string $threadId): array;

    /**
     * Find reports by status with pagination.
     *
     * @return PaginationResult<ThreadReport>
     */
    public function findByStatus(
        ReportStatus $status,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult;

    /**
     * Count pending reports for a thread.
     */
    public function countPendingForThread(string $threadId): int;

    public function save(ThreadReport $report): void;

    public function delete(ThreadReport $report): void;
}
