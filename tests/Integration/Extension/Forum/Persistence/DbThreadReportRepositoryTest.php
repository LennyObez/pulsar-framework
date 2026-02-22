<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Internal\Persistence\DbThreadReportRepository;
use Pulsar\Extension\Forum\Report\ThreadReport;

#[CoversClass(DbThreadReportRepository::class)]
final class DbThreadReportRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbThreadReportRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_thread_reports (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                thread_id VARCHAR(36) NOT NULL,
                reporter_id VARCHAR(36) NOT NULL,
                reason TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                moderator_id VARCHAR(36) DEFAULT NULL,
                moderator_note TEXT DEFAULT NULL,
                reviewed_by VARCHAR(36) DEFAULT NULL,
                resolution_note TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TEXT DEFAULT NULL
            )
            SQL);

        $this->repository = new DbThreadReportRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $report = ThreadReport::create(
            id: 'tr-001',
            threadId: 'thread-001',
            reporterId: 'user-001',
            reason: 'Off-topic content',
        );
        $this->repository->save($report);

        $found = $this->repository->findById('tr-001');

        self::assertNotNull($found);
        self::assertSame('tr-001', $found->id);
        self::assertSame('thread-001', $found->threadId);
        self::assertSame('user-001', $found->reporterId);
        self::assertSame('Off-topic content', $found->reason);
        self::assertSame(ReportStatus::Pending, $found->status);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByReporterAndThreadReturnsPendingReport(): void
    {
        $report = ThreadReport::create(
            id: 'tr-dup',
            threadId: 'thread-dup',
            reporterId: 'user-dup',
            reason: 'Duplicate',
        );
        $this->repository->save($report);

        $found = $this->repository->findByReporterAndThread('user-dup', 'thread-dup');

        self::assertNotNull($found);
        self::assertSame('tr-dup', $found->id);
    }

    #[Test]
    public function findByReporterAndThreadReturnsNullWhenNoMatch(): void
    {
        self::assertNull($this->repository->findByReporterAndThread('user-x', 'thread-x'));
    }

    #[Test]
    public function findByThreadReturnsAllReportsForThread(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $report = ThreadReport::create(
                id: "tr-bt-{$i}",
                threadId: 'thread-target',
                reporterId: "user-{$i}",
                reason: "Reason {$i}",
            );
            $this->repository->save($report);
        }

        $reports = $this->repository->findByThread('thread-target');

        self::assertCount(3, $reports);
    }

    #[Test]
    public function findByStatusReturnsPaginated(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $report = ThreadReport::create(
                id: "tr-s-{$i}",
                threadId: "thread-{$i}",
                reporterId: 'user-001',
                reason: "Reason {$i}",
            );
            $this->repository->save($report);
        }

        $result = $this->repository->findByStatus(ReportStatus::Pending, page: 1, perPage: 3);

        self::assertSame(5, $result->total);
        self::assertCount(3, $result->items);
        self::assertTrue($result->hasMore);
    }

    #[Test]
    public function countPendingForThread(): void
    {
        for ($i = 1; $i <= 2; $i++) {
            $report = ThreadReport::create(
                id: "tr-cp-{$i}",
                threadId: 'thread-pending',
                reporterId: "user-{$i}",
                reason: 'Pending',
            );
            $this->repository->save($report);
        }

        $dismissed = new ThreadReport(
            id: 'tr-cp-dismissed',
            tenantId: null,
            threadId: 'thread-pending',
            reporterId: 'user-3',
            reason: 'Dismissed',
            status: ReportStatus::Dismissed,
            moderatorId: 'mod-1',
            moderatorNote: 'OK',
            createdAt: new DateTimeImmutable(),
            reviewedAt: new DateTimeImmutable(),
        );
        $this->repository->save($dismissed);

        self::assertSame(2, $this->repository->countPendingForThread('thread-pending'));
    }

    #[Test]
    public function saveUpdatesExistingReport(): void
    {
        $report = ThreadReport::create(
            id: 'tr-upd',
            threadId: 'thread-upd',
            reporterId: 'user-001',
            reason: 'Offensive',
        );
        $this->repository->save($report);

        $reviewed = new ThreadReport(
            id: 'tr-upd',
            tenantId: null,
            threadId: 'thread-upd',
            reporterId: 'user-001',
            reason: 'Offensive',
            status: ReportStatus::UnderReview,
            moderatorId: 'mod-001',
            moderatorNote: 'Under investigation',
            createdAt: $report->createdAt,
            reviewedAt: new DateTimeImmutable(),
        );
        $this->repository->save($reviewed);

        $found = $this->repository->findById('tr-upd');
        self::assertNotNull($found);
        self::assertSame(ReportStatus::UnderReview, $found->status);
        self::assertSame('mod-001', $found->moderatorId);
    }

    #[Test]
    public function deleteRemovesReport(): void
    {
        $report = ThreadReport::create(
            id: 'tr-del',
            threadId: 'thread-del',
            reporterId: 'user-001',
            reason: 'To delete',
        );
        $this->repository->save($report);

        $this->repository->delete($report);

        self::assertNull($this->repository->findById('tr-del'));
    }
}
