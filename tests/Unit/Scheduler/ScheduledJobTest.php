<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\ScheduleBuilder;
use Pulsar\Scheduler\ScheduledJob;
use RuntimeException;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function random_bytes;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;

use const DIRECTORY_SEPARATOR;

final class ScheduledJobTest extends TestCase
{
    /** @var list<string> Temp files created during test — cleaned in tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
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
    public function outputWriteFailureIsLoggedAsWarning(): void
    {
        // Target a path whose parent directory does not exist, so
        // file_put_contents() returns false on every platform without
        // relying on permissions or disk state. No file is created.
        $unwritablePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'pulsar_sched_missing_dir_' . bin2hex(random_bytes(8))
            . DIRECTORY_SEPARATOR . 'output.log';

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(
                static fn(string $message): bool => str_contains($message, 'failed to write output')
                    && str_contains($message, 'warn-job'),
            ));

        $job = ScheduleBuilder::job('warn-job', static fn() => 'some output')
            ->daily()
            ->sendOutputTo($unwritablePath)
            ->build();

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
            logger: $logger,
        );

        // The job itself still succeeds — the write failure is non-fatal but
        // must be surfaced rather than silently swallowed.
        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertFalse(file_exists($unwritablePath));
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
    public function aJobWithoutOverlapPreventionNeedsNoLock(): void
    {
        // The overlap lock is required only when overlap prevention is asked
        // for. Overlap behaviour itself is covered by
        // {@see ScheduledJobOverlapLockTest}, which observes the lock held.
        $job = new ScheduledJob(
            name: 'unguarded',
            schedule: Schedule::daily(),
            callback: static fn(): string => 'ran',
        );

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        self::assertFalse($job->preventsOverlap());
        self::assertSame(JobStatus::Success, $job->execute($context)->status);
    }
}
