<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Config\HistoryRetentionConfig;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Scheduler\HistoryCleanupJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use RuntimeException;

#[CoversClass(HistoryCleanupJob::class)]
final class HistoryCleanupJobTest extends TestCase
{
    #[Test]
    public function getNameReturnsExpectedValue(): void
    {
        $job = $this->createJob();

        self::assertSame('health-status:cleanup', $job->getName());
    }

    #[Test]
    public function getScheduleReturnsSixHourInterval(): void
    {
        $job = $this->createJob();
        $schedule = $job->getSchedule();

        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertSame('0 */6 * * *', $schedule->expression);
    }

    #[Test]
    public function getDescriptionReturnsNonEmptyString(): void
    {
        $job = $this->createJob();

        self::assertNotEmpty($job->getDescription());
    }

    #[Test]
    public function executeDeletesOldSnapshots(): void
    {
        $cutoffPassed = null;
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('deleteSnapshotsOlderThan')->willReturnCallback(
            static function (DateTimeImmutable $cutoff) use (&$cutoffPassed): int {
                $cutoffPassed = $cutoff;

                return 42;
            },
        );

        $config = new HistoryRetentionConfig(maxAgeDays: 7, maxRows: 100_000, cleanupIntervalHours: 6);
        $job = new HistoryCleanupJob($store, $config);

        $now = new DateTimeImmutable('2026-03-27T12:00:00Z');
        $context = new JobContext(scheduledAt: $now, startedAt: $now);

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertNotNull($cutoffPassed);

        // Cutoff should be approximately 7 days before now
        $expectedCutoff = $now->modify('-7 days');
        $diff = $expectedCutoff->getTimestamp() - $cutoffPassed->getTimestamp();
        self::assertLessThanOrEqual(1, abs($diff), 'Cutoff should be 7 days before execution time');
    }

    #[Test]
    public function executeUsesConfiguredMaxAgeDays(): void
    {
        $cutoffPassed = null;
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('deleteSnapshotsOlderThan')->willReturnCallback(
            static function (DateTimeImmutable $cutoff) use (&$cutoffPassed): int {
                $cutoffPassed = $cutoff;

                return 0;
            },
        );

        $config = new HistoryRetentionConfig(maxAgeDays: 30, maxRows: 100_000, cleanupIntervalHours: 6);
        $job = new HistoryCleanupJob($store, $config);

        $now = new DateTimeImmutable('2026-04-15T00:00:00Z');
        $context = new JobContext(scheduledAt: $now, startedAt: $now);

        $job->execute($context);

        self::assertNotNull($cutoffPassed);
        $expectedCutoff = $now->modify('-30 days');
        $diff = $expectedCutoff->getTimestamp() - $cutoffPassed->getTimestamp();
        self::assertLessThanOrEqual(1, abs($diff));
    }

    #[Test]
    public function executeReturnsSuccessWithOutputCount(): void
    {
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('deleteSnapshotsOlderThan')->willReturn(100);

        $config = new HistoryRetentionConfig(maxAgeDays: 7);
        $job = new HistoryCleanupJob($store, $config);

        $now = new DateTimeImmutable();
        $context = new JobContext(scheduledAt: $now, startedAt: $now);

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('100', $result->output);
    }

    #[Test]
    public function executeReturnsFailureOnException(): void
    {
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('deleteSnapshotsOlderThan')->willThrowException(
            new RuntimeException('Database error'),
        );

        $config = new HistoryRetentionConfig(maxAgeDays: 7);
        $job = new HistoryCleanupJob($store, $config);

        $now = new DateTimeImmutable();
        $context = new JobContext(scheduledAt: $now, startedAt: $now);

        $result = $job->execute($context);

        self::assertSame(JobStatus::Failure, $result->status);
        self::assertNotNull($result->exception);
    }

    private function createJob(): HistoryCleanupJob
    {
        return new HistoryCleanupJob(
            $this->createStub(HealthHistoryStoreInterface::class),
            new HistoryRetentionConfig(),
        );
    }
}
