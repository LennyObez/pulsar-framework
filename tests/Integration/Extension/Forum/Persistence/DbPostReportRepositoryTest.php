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
use Pulsar\Extension\Forum\Internal\Persistence\DbPostReportRepository;
use Pulsar\Extension\Forum\Report\PostReport;

#[CoversClass(DbPostReportRepository::class)]
final class DbPostReportRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbPostReportRepository $repository;

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
            CREATE TABLE IF NOT EXISTS forum_post_reports (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                post_id VARCHAR(36) NOT NULL,
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

        $this->repository = new DbPostReportRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $report = PostReport::create(
            id: 'pr-001',
            postId: 'post-001',
            reporterId: 'user-001',
            reason: 'Spam content',
        );
        $this->repository->save($report);

        $found = $this->repository->findById('pr-001');

        self::assertNotNull($found);
        self::assertSame('pr-001', $found->id);
        self::assertSame('post-001', $found->postId);
        self::assertSame('user-001', $found->reporterId);
        self::assertSame('Spam content', $found->reason);
        self::assertSame(ReportStatus::Pending, $found->status);
        self::assertNull($found->moderatorId);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByReporterAndPostReturnsPendingReport(): void
    {
        $report = PostReport::create(
            id: 'pr-dup',
            postId: 'post-dup',
            reporterId: 'user-dup',
            reason: 'Duplicate report',
        );
        $this->repository->save($report);

        $found = $this->repository->findByReporterAndPost('user-dup', 'post-dup');

        self::assertNotNull($found);
        self::assertSame('pr-dup', $found->id);
    }

    #[Test]
    public function findByReporterAndPostReturnsNullWhenNoMatch(): void
    {
        self::assertNull($this->repository->findByReporterAndPost('user-x', 'post-x'));
    }

    #[Test]
    public function findByPostReturnsAllReportsForPost(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $report = PostReport::create(
                id: "pr-bp-{$i}",
                postId: 'post-target',
                reporterId: "user-{$i}",
                reason: "Reason {$i}",
            );
            $this->repository->save($report);
        }

        $reports = $this->repository->findByPost('post-target');

        self::assertCount(3, $reports);
    }

    #[Test]
    public function findByStatusReturnsPaginatedResults(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $report = PostReport::create(
                id: "pr-s-{$i}",
                postId: "post-{$i}",
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
    public function countPendingForPost(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $report = PostReport::create(
                id: "pr-cp-{$i}",
                postId: 'post-pending',
                reporterId: "user-{$i}",
                reason: 'Pending report',
            );
            $this->repository->save($report);
        }

        $reviewed = new PostReport(
            id: 'pr-cp-reviewed',
            tenantId: null,
            postId: 'post-pending',
            reporterId: 'user-4',
            reason: 'Reviewed report',
            status: ReportStatus::Dismissed,
            moderatorId: 'mod-1',
            moderatorNote: 'Dismissed',
            createdAt: new DateTimeImmutable(),
            reviewedAt: new DateTimeImmutable(),
        );
        $this->repository->save($reviewed);

        self::assertSame(3, $this->repository->countPendingForPost('post-pending'));
    }

    #[Test]
    public function saveUpdatesExistingReport(): void
    {
        $report = PostReport::create(
            id: 'pr-upd',
            postId: 'post-upd',
            reporterId: 'user-001',
            reason: 'Bad content',
        );
        $this->repository->save($report);

        $reviewed = new PostReport(
            id: 'pr-upd',
            tenantId: null,
            postId: 'post-upd',
            reporterId: 'user-001',
            reason: 'Bad content',
            status: ReportStatus::Dismissed,
            moderatorId: 'mod-001',
            moderatorNote: 'No violation found',
            createdAt: $report->createdAt,
            reviewedAt: new DateTimeImmutable(),
        );
        $this->repository->save($reviewed);

        $found = $this->repository->findById('pr-upd');
        self::assertNotNull($found);
        self::assertSame(ReportStatus::Dismissed, $found->status);
        self::assertSame('mod-001', $found->moderatorId);
    }

    #[Test]
    public function deleteRemovesReport(): void
    {
        $report = PostReport::create(
            id: 'pr-del',
            postId: 'post-del',
            reporterId: 'user-001',
            reason: 'To delete',
        );
        $this->repository->save($report);

        $this->repository->delete($report);

        self::assertNull($this->repository->findById('pr-del'));
    }
}
