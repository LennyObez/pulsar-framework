<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\CallbackJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use RuntimeException;

#[CoversClass(CallbackJob::class)]
#[CoversClass(JobResult::class)]
final class CallbackJobTest extends TestCase
{
    #[Test]
    public function getNameReturnsJobName(): void
    {
        $job = new CallbackJob(
            name: 'cleanup-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'done',
            description: 'Cleans up temporary files',
        );

        self::assertSame('cleanup-job', $job->getName());
    }

    #[Test]
    public function getDescriptionReturnsJobDescription(): void
    {
        $job = new CallbackJob(
            name: 'cleanup-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'done',
            description: 'Cleans up temporary files',
        );

        self::assertSame('Cleans up temporary files', $job->getDescription());
    }

    #[Test]
    public function getDescriptionReturnsEmptyStringByDefault(): void
    {
        $job = new CallbackJob(
            name: 'cleanup-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'done',
        );

        self::assertSame('', $job->getDescription());
    }

    #[Test]
    public function getScheduleReturnsScheduleInstance(): void
    {
        $schedule = Schedule::hourly();
        $job = new CallbackJob(
            name: 'test-job',
            schedule: $schedule,
            callback: static fn(JobContext $ctx): string => 'ok',
        );

        self::assertSame($schedule, $job->getSchedule());
    }

    #[Test]
    public function executeRunsCallbackAndReturnsSuccessResult(): void
    {
        $job = new CallbackJob(
            name: 'test-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'task completed',
            description: 'Test job',
        );

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame('test-job', $result->jobName);
        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('task completed', $result->output);
        self::assertNull($result->exception);
    }

    #[Test]
    public function executeWithNullReturnProducesEmptyOutput(): void
    {
        $job = new CallbackJob(
            name: 'silent-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): ?string => null,
        );

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('', $result->output);
    }

    #[Test]
    public function executeCatchesExceptionAndReturnsFailureResult(): void
    {
        $exception = new RuntimeException('Something went wrong');

        $job = new CallbackJob(
            name: 'failing-job',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx) use ($exception): never {
                throw $exception;
            },
            description: 'A job that fails',
        );

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame('failing-job', $result->jobName);
        self::assertSame(JobStatus::Failure, $result->status);
        self::assertNotNull($result->exception);
        self::assertSame('Something went wrong', $result->exception->getMessage());
    }
}
