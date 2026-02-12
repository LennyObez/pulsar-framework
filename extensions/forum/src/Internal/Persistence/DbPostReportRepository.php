<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;

use function ceil;
use function max;
use function min;

#[Internal(reason: 'Raw-DB repository — use PostReportRepositoryInterface for public API')]
final readonly class DbPostReportRepository implements PostReportRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT r.*
        FROM forum_post_reports r
        WHERE r.id = :id
        SQL;

    private const string SQL_FIND_BY_REPORTER_AND_POST = <<<'SQL'
        SELECT r.*
        FROM forum_post_reports r
        WHERE r.reporter_id = :reporter_id
            AND r.post_id = :post_id
            AND r.status = 'pending'
        LIMIT 1
        SQL;

    private const string SQL_FIND_BY_POST = <<<'SQL'
        SELECT r.*
        FROM forum_post_reports r
        WHERE r.post_id = :post_id
        ORDER BY r.created_at DESC
        LIMIT 100
        SQL;

    private const string SQL_COUNT_BY_STATUS = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_post_reports r
        WHERE r.status = :status
            AND r.tenant_id IS NOT DISTINCT FROM :tenant_id
        SQL;

    private const string SQL_FIND_BY_STATUS = <<<'SQL'
        SELECT r.*
        FROM forum_post_reports r
        WHERE r.status = :status
            AND r.tenant_id IS NOT DISTINCT FROM :tenant_id
        ORDER BY r.created_at ASC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_COUNT_PENDING_FOR_POST = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_post_reports r
        WHERE r.post_id = :post_id AND r.status = 'pending'
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO forum_post_reports (
            id, tenant_id, post_id, reporter_id, reason,
            status, moderator_id, moderator_note,
            created_at, reviewed_at
        ) VALUES (
            :id, :tenant_id, :post_id, :reporter_id, :reason,
            :status, :moderator_id, :moderator_note,
            :created_at, :reviewed_at
        )
        ON CONFLICT (id) DO UPDATE SET
            status = EXCLUDED.status,
            moderator_id = EXCLUDED.moderator_id,
            moderator_note = EXCLUDED.moderator_note,
            reviewed_at = EXCLUDED.reviewed_at
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_post_reports WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?PostReport
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByReporterAndPost(string $reporterId, string $postId): ?PostReport
    {
        $result = $this->connection->query(self::SQL_FIND_BY_REPORTER_AND_POST, [
            'reporter_id' => $reporterId,
            'post_id' => $postId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByPost(string $postId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_POST, [
            'post_id' => $postId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findByStatus(
        ReportStatus $status,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $effectiveTenantId = $tenantId ?? $this->tenantId;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_STATUS, [
            'status' => $status->value,
            'tenant_id' => $effectiveTenantId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_STATUS, [
            'status' => $status->value,
            'tenant_id' => $effectiveTenantId,
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function countPendingForPost(string $postId): int
    {
        $result = $this->connection->query(self::SQL_COUNT_PENDING_FOR_POST, [
            'post_id' => $postId,
        ]);

        return $result->first()?->getInt('total') ?? 0;
    }

    public function save(PostReport $report): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $report->id,
            'tenant_id' => $report->tenantId,
            'post_id' => $report->postId,
            'reporter_id' => $report->reporterId,
            'reason' => $report->reason,
            'status' => $report->status->value,
            'moderator_id' => $report->moderatorId,
            'moderator_note' => $report->moderatorNote,
            'created_at' => $report->createdAt->format('c'),
            'reviewed_at' => $report->reviewedAt?->format('c'),
        ]);
    }

    public function delete(PostReport $report): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $report->id]);
    }

    private static function hydrate(Row $row): PostReport
    {
        return new PostReport(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            postId: $row->getString('post_id'),
            reporterId: $row->getString('reporter_id'),
            reason: $row->getString('reason'),
            status: ReportStatus::from($row->getString('status')),
            moderatorId: $row->getNullableString('moderator_id'),
            moderatorNote: $row->getNullableString('moderator_note'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            reviewedAt: self::toDateTime($row->getNullableString('reviewed_at')),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
