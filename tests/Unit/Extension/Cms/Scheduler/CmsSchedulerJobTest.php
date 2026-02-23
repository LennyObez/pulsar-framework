<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Scheduler\BackupRetentionJob;
use Pulsar\Extension\Cms\Internal\Scheduler\ExpiredSessionCleanupJob;
use Pulsar\Extension\Cms\Internal\Scheduler\LinkHealthCheckJob;
use Pulsar\Extension\Cms\Internal\Scheduler\ScheduledPublishingJob;
use Pulsar\Extension\Cms\Internal\Scheduler\SearchAnalyticsCleanupJob;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;
use Pulsar\Extension\Cms\Tools\Backup;
use Pulsar\Extension\Cms\Tools\BackupScope;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobStatus;
use RuntimeException;

use function array_map;
use function array_unique;
use function count;

#[CoversClass(LinkHealthCheckJob::class)]
#[CoversClass(ScheduledPublishingJob::class)]
#[CoversClass(SearchAnalyticsCleanupJob::class)]
#[CoversClass(ExpiredSessionCleanupJob::class)]
#[CoversClass(BackupRetentionJob::class)]
final class CmsSchedulerJobTest extends TestCase
{
    #[Test]
    public function allJobsHaveUniqueNames(): void
    {
        $jobs = $this->allJobs();
        $names = array_map(static fn(JobInterface $job): string => $job->getName(), $jobs);

        self::assertSame(count($names), count(array_unique($names)), 'Job names must be unique');
    }

    #[Test]
    public function allJobsHaveValidCronExpressions(): void
    {
        foreach ($this->allJobs() as $job) {
            $expression = $job->getSchedule()->expression;

            // Basic cron expression validation: 5 whitespace-separated fields
            self::assertMatchesRegularExpression(
                '/^\S+\s+\S+\s+\S+\s+\S+\s+\S+$/',
                $expression,
                "Job '{$job->getName()}' has invalid cron expression: {$expression}",
            );
        }
    }

    #[Test]
    public function allJobsHaveNonEmptyDescriptions(): void
    {
        foreach ($this->allJobs() as $job) {
            self::assertNotEmpty(
                $job->getDescription(),
                "Job '{$job->getName()}' must have a non-empty description",
            );
        }
    }

    #[Test]
    public function allJobNamesArePrefixedWithCms(): void
    {
        foreach ($this->allJobs() as $job) {
            self::assertMatchesRegularExpression(
                '/^cms:/',
                $job->getName(),
                "Job name '{$job->getName()}' must start with 'cms:' prefix",
            );
        }
    }

    #[Test]
    public function linkHealthCheckJobCallsCheckAll(): void
    {
        /** @var LinkHealthServiceInterface&Stub $service */
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('checkAll')->willReturn([]);

        $job = new LinkHealthCheckJob($service);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('0 issue(s) found', $result->output);
    }

    #[Test]
    public function linkHealthCheckJobReportsFailure(): void
    {
        /** @var LinkHealthServiceInterface&Stub $service */
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('checkAll')->willThrowException(new RuntimeException('Connection timeout'));

        $job = new LinkHealthCheckJob($service);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Failure, $result->status);
        self::assertNotNull($result->exception);
        self::assertSame('Connection timeout', $result->exception->getMessage());
    }

    #[Test]
    public function searchAnalyticsJobCallsGetTotals(): void
    {
        /** @var SearchAnalyticsRepositoryInterface&Stub $repo */
        $repo = $this->createStub(SearchAnalyticsRepositoryInterface::class);
        $repo->method('getTotals')->willReturn([
            'total_searches' => 150,
            'unique_queries' => 42,
        ]);

        $job = new SearchAnalyticsCleanupJob($repo);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('150 total searches', $result->output);
        self::assertStringContainsString('42 unique queries', $result->output);
    }

    #[Test]
    public function searchAnalyticsJobReportsFailure(): void
    {
        /** @var SearchAnalyticsRepositoryInterface&Stub $repo */
        $repo = $this->createStub(SearchAnalyticsRepositoryInterface::class);
        $repo->method('getTotals')->willThrowException(new RuntimeException('DB unreachable'));

        $job = new SearchAnalyticsCleanupJob($repo);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Failure, $result->status);
        self::assertNotNull($result->exception);
    }

    #[Test]
    public function expiredSessionCleanupJobCallsBothServices(): void
    {
        /** @var PreviewSessionRepositoryInterface&Stub $previewRepo */
        $previewRepo = $this->createStub(PreviewSessionRepositoryInterface::class);
        $previewRepo->method('deleteExpired')->willReturn(3);

        /** @var ContentLockServiceInterface&Stub $lockService */
        $lockService = $this->createStub(ContentLockServiceInterface::class);
        $lockService->method('cleanupExpired')->willReturn(5);

        $job = new ExpiredSessionCleanupJob($previewRepo, $lockService);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('3 expired preview session(s)', $result->output);
        self::assertStringContainsString('5 expired lock(s)', $result->output);
    }

    #[Test]
    public function expiredSessionCleanupJobReportsFailure(): void
    {
        /** @var PreviewSessionRepositoryInterface&Stub $previewRepo */
        $previewRepo = $this->createStub(PreviewSessionRepositoryInterface::class);
        $previewRepo->method('deleteExpired')->willThrowException(new RuntimeException('Lock error'));

        /** @var ContentLockServiceInterface&Stub $lockService */
        $lockService = $this->createStub(ContentLockServiceInterface::class);

        $job = new ExpiredSessionCleanupJob($previewRepo, $lockService);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Failure, $result->status);
    }

    #[Test]
    public function backupRetentionJobDeletesOldBackups(): void
    {
        $oldBackup = new Backup(
            id: 'old-backup-id',
            scope: new BackupScope(),
            storagePath: 'backups/cms/old.json',
            hash: 'abc123',
            size: 1024,
            createdAt: new DateTimeImmutable('-60 days'),
            createdBy: 'user-1',
        );

        $recentBackup = new Backup(
            id: 'recent-backup-id',
            scope: new BackupScope(),
            storagePath: 'backups/cms/recent.json',
            hash: 'def456',
            size: 2048,
            createdAt: new DateTimeImmutable('-5 days'),
            createdBy: 'user-1',
        );

        /** @var BackupServiceInterface&Stub $backupService */
        $backupService = $this->createStub(BackupServiceInterface::class);
        $backupService->method('listBackups')->willReturn([$oldBackup, $recentBackup]);

        $job = new BackupRetentionJob($backupService, 30);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('1 of 2 backup(s) deleted', $result->output);
        self::assertStringContainsString('retention: 30 days', $result->output);
    }

    #[Test]
    public function backupRetentionJobKeepsAllRecentBackups(): void
    {
        $recentBackup = new Backup(
            id: 'recent-backup-id',
            scope: new BackupScope(),
            storagePath: 'backups/cms/recent.json',
            hash: 'def456',
            size: 2048,
            createdAt: new DateTimeImmutable('-2 days'),
            createdBy: 'user-1',
        );

        /** @var BackupServiceInterface&Stub $backupService */
        $backupService = $this->createStub(BackupServiceInterface::class);
        $backupService->method('listBackups')->willReturn([$recentBackup]);

        $job = new BackupRetentionJob($backupService);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('0 of 1 backup(s) deleted', $result->output);
    }

    #[Test]
    public function backupRetentionJobReportsFailure(): void
    {
        /** @var BackupServiceInterface&Stub $backupService */
        $backupService = $this->createStub(BackupServiceInterface::class);
        $backupService->method('listBackups')->willThrowException(new RuntimeException('Disk full'));

        $job = new BackupRetentionJob($backupService);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Failure, $result->status);
        self::assertNotNull($result->exception);
    }

    /**
     * @return list<JobInterface>
     */
    private function allJobs(): array
    {
        /** @var LinkHealthServiceInterface&Stub $linkHealth */
        $linkHealth = $this->createStub(LinkHealthServiceInterface::class);

        /** @var SearchAnalyticsRepositoryInterface&Stub $analyticsRepo */
        $analyticsRepo = $this->createStub(SearchAnalyticsRepositoryInterface::class);

        /** @var PreviewSessionRepositoryInterface&Stub $previewRepo */
        $previewRepo = $this->createStub(PreviewSessionRepositoryInterface::class);

        /** @var ContentLockServiceInterface&Stub $lockService */
        $lockService = $this->createStub(ContentLockServiceInterface::class);

        /** @var BackupServiceInterface&Stub $backupService */
        $backupService = $this->createStub(BackupServiceInterface::class);

        /** @var ContentRepositoryInterface&Stub $contentRepo */
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        return [
            new LinkHealthCheckJob($linkHealth),
            new ScheduledPublishingJob($contentRepo),
            new SearchAnalyticsCleanupJob($analyticsRepo),
            new ExpiredSessionCleanupJob($previewRepo, $lockService),
            new BackupRetentionJob($backupService),
        ];
    }

    private function createContext(): JobContext
    {
        return new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );
    }
}
