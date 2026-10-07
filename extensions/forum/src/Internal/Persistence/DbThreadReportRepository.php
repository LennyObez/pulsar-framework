<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;

use function ceil;
use function max;
use function min;

#[Internal(reason: 'Raw-DB repository; use ThreadReportRepositoryInterface for public API')]
final readonly class DbThreadReportRepository implements ThreadReportRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT r.*
        FROM forum_thread_reports r
        WHERE r.id = :id
        SQL;

    private const string SQL_FIND_BY_REPORTER_AND_THREAD = <<<'SQL'
        SELECT r.*
        FROM forum_thread_reports r
        WHERE r.reporter_id = :reporter_id
            AND r.thread_id = :thread_id
            AND r.status = 'pending'
        LIMIT 1
        SQL;

    private const string SQL_FIND_BY_THREAD = <<<'SQL'
        SELECT r.*
        FROM forum_thread_reports r
        WHERE r.thread_id = :thread_id
        ORDER BY r.created_at DESC
        LIMIT 100
        SQL;

    private const string SQL_COUNT_BY_STATUS = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_thread_reports r
        WHERE r.status = :status
            AND COALESCE(r.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_FIND_BY_STATUS = <<<'SQL'
        SELECT r.*
        FROM forum_thread_reports r
        WHERE r.status = :status
            AND COALESCE(r.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        ORDER BY r.created_at ASC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_COUNT_PENDING_FOR_THREAD = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_thread_reports r
        WHERE r.thread_id = :thread_id AND r.status = 'pending'
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'thread_id', 'reporter_id', 'reason',
        'status', 'moderator_id', 'moderator_note',
        'created_at', 'reviewed_at',
    ];

    private const array UPSERT_UPDATE = [
        'status', 'moderator_id', 'moderator_note', 'reviewed_at',
    ];

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_thread_reports WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?ThreadReport
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByReporterAndThread(string $reporterId, string $threadId): ?ThreadReport
    {
        $result = $this->connection->query(self::SQL_FIND_BY_REPORTER_AND_THREAD, [
            'reporter_id' => $reporterId,
            'thread_id' => $threadId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByThread(string $threadId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_THREAD, [
            'thread_id' => $threadId,
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
        $tenantKey = $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_STATUS, [
            'status' => $status->value,
            'tenant_key' => $tenantKey,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_STATUS, [
            'status' => $status->value,
            'tenant_key' => $tenantKey,
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

    public function countPendingForThread(string $threadId): int
    {
        $result = $this->connection->query(self::SQL_COUNT_PENDING_FOR_THREAD, [
            'thread_id' => $threadId,
        ]);

        return $result->first()?->getInt('total') ?? 0;
    }

    public function save(ThreadReport $report): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_thread_reports',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $report->id,
            'tenant_id' => $report->tenantId,
            'thread_id' => $report->threadId,
            'reporter_id' => $report->reporterId,
            'reason' => $report->reason,
            'status' => $report->status->value,
            'moderator_id' => $report->moderatorId,
            'moderator_note' => $report->moderatorNote,
            'created_at' => $report->createdAt->format('c'),
            'reviewed_at' => $report->reviewedAt?->format('c'),
        ]);
    }

    public function delete(ThreadReport $report): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $report->id]);
    }

    private static function hydrate(Row $row): ThreadReport
    {
        return new ThreadReport(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            threadId: $row->getString('thread_id'),
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
