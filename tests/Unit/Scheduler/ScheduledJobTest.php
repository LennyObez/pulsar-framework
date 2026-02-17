<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\ScheduleBuilder;
use Pulsar\Scheduler\ScheduledJob;
use RuntimeException;

use function file_get_contents;
use function sys_get_temp_dir;
use function tempnam;

final class ScheduledJobTest extends TestCase
{
    /** @var list<string> Temp files created during test — cleaned in tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        ScheduledJob::resetLocks();
    }

    protected function tearDown(): void
    {
        ScheduledJob::resetLocks();

        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                // Path is always from tempnam() — safe to remove
                @unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    /**
     * Create a temp file registered for automatic cleanup.
     */
    private function createSafeTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_sched_test_');
        self::assertNotFalse($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    #[Test]
    public function executeCallsCallbackAndReturnsSuccess(): void
    {
        $job = ScheduleBuilder::job('test-job', static fn() => 'output text')
            ->daily()
            ->build();

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('output text', $result->output);
    }

    #[Test]
    public function executeReturnsFailureOnException(): void
    {
        $job = ScheduleBuilder::job('fail-job', static function (): never {
            throw new RuntimeException('boom');
        })->daily()->build();

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Failure, $result->status);
        self::assertSame('boom', $result->exception?->getMessage());
    }

    #[Test]
    public function overlapPreventionSkipsWhenAlreadyRunning(): void
    {
        $executionCount = 0;
        $callback = static function () use (&$executionCount): string {
            $executionCount++;
            return 'done';
        };

        // Create two jobs with the same name, overlap prevention enabled
        $job1 = new ScheduledJob(
            name: 'overlap-test',
            schedule: Schedule::daily(),
            callback: $callback,
            preventOverlap: true,
        );

        // Simulate job1 running by acquiring the lock manually
        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        // Job 1 executes successfully
        $result1 = $job1->execute($context);
        self::assertSame(JobStatus::Success, $result1->status);

        // After job1 completes, lock is released — job2 should also succeed
        $job2 = new ScheduledJob(
            name: 'overlap-test',
            schedule: Schedule::daily(),
            callback: $callback,
            preventOverlap: true,
        );
        $result2 = $job2->execute($context);
        self::assertSame(JobStatus::Success, $result2->status);
        self::assertSame(2, $executionCount);
    }

    #[Test]
    public function appendOutputWritesToFile(): void
    {
        $tempFile = $this->createSafeTempFile();

        $job = ScheduleBuilder::job('output-job', static fn() => 'line1')
            ->daily()
            ->appendOutputTo($tempFile)
            ->build();

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $job->execute($context);
        $job->execute($context);

        $contents = file_get_contents($tempFile);
        self::assertSame("line1\nline1\n", $contents);
    }

    #[Test]
    public function sendOutputOverwritesFile(): void
    {
        $tempFile = $this->createSafeTempFile();

        $job = ScheduleBuilder::job('write-job', static fn() => 'latest')
            ->daily()
            ->sendOutputTo($tempFile)
            ->build();

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $job->execute($context);
        $job->execute($context);

        $contents = file_get_contents($tempFile);
        self::assertSame("latest\n", $contents);
    }

    #[Test]
    public function runsInMaintenanceModeReturnsFalseByDefault(): void
    {
        $job = ScheduleBuilder::job('test', static fn() => null)->daily()->build();

        self::assertFalse($job->runsInMaintenanceMode());
    }

    #[Test]
    public function preventsOverlapReturnsFalseByDefault(): void
    {
        $job = ScheduleBuilder::job('test', static fn() => null)->daily()->build();

        self::assertFalse($job->preventsOverlap());
    }

    #[Test]
    public function nullOutputIsHandledAsEmptyString(): void
    {
        $job = ScheduleBuilder::job('null-output', static fn() => null)
            ->daily()
            ->build();

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('', $result->output);
    }

    #[Test]
    public function lockReleasedAfterException(): void
    {
        $callCount = 0;
        $job = new ScheduledJob(
            name: 'exception-lock-test',
            schedule: Schedule::daily(),
            callback: static function () use (&$callCount): string {
                $callCount++;
                if ($callCount === 1) {
                    throw new RuntimeException('first run fails');
                }
                return 'second run succeeds';
            },
            preventOverlap: true,
        );

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result1 = $job->execute($context);
        self::assertSame(JobStatus::Failure, $result1->status);

        // Lock should be released despite failure — second run should proceed
        $result2 = $job->execute($context);
        self::assertSame(JobStatus::Success, $result2->status);
    }
}
