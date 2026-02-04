<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\CallbackJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Scheduler;
use Pulsar\Scheduler\SchedulerTickResult;
use RuntimeException;

#[CoversClass(Scheduler::class)]
#[CoversClass(CallbackJob::class)]
#[CoversClass(JobRegistry::class)]
#[CoversClass(JobResult::class)]
#[CoversClass(SchedulerTickResult::class)]
#[CoversClass(Schedule::class)]
#[CoversClass(JobContext::class)]
final class SchedulerExecutionTest extends TestCase
{
    #[Test]
    public function fullTickWithMultipleJobs(): void
    {
        $registry = new JobRegistry();

        $executionOrder = [];

        $registry->register(new CallbackJob(
            name: 'job-alpha',
            schedule: Schedule::everyMinute(),
            callback: function (JobContext $ctx) use (&$executionOrder): string {
                $executionOrder[] = 'alpha';
                return 'alpha-done';
            },
            description: 'First job',
        ));

        $registry->register(new CallbackJob(
            name: 'job-beta',
            schedule: Schedule::everyMinute(),
            callback: function (JobContext $ctx) use (&$executionOrder): string {
                $executionOrder[] = 'beta';
                return 'beta-done';
            },
            description: 'Second job',
        ));

        $scheduler = new Scheduler($registry);
        $result = $scheduler->tick();

        self::assertSame(2, $result->jobsDue);
        self::assertSame(2, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
        self::assertFalse($result->hasFailures());
        self::assertCount(2, $result->results);
        self::assertContains('alpha', $executionOrder);
        self::assertContains('beta', $executionOrder);

        // All results should be success
        foreach ($result->results as $jobResult) {
            self::assertSame(JobStatus::Success, $jobResult->status);
        }
    }

    #[Test]
    public function onlyDueJobsAreExecuted(): void
    {
        $registry = new JobRegistry();

        $everyMinuteRan = false;
        $dailyRan = false;

        $registry->register(new CallbackJob(
            name: 'every-minute-job',
            schedule: Schedule::everyMinute(),
            callback: function (JobContext $ctx) use (&$everyMinuteRan): string {
                $everyMinuteRan = true;
                return 'minute-done';
            },
            description: 'Runs every minute',
        ));

        // Daily at midnight -- use a time that is NOT midnight to ensure it does not run
        $registry->register(new CallbackJob(
            name: 'daily-job',
            schedule: Schedule::dailyAt('03:00'),
            callback: function (JobContext $ctx) use (&$dailyRan): string {
                $dailyRan = true;
                return 'daily-done';
            },
            description: 'Runs daily at 3 AM',
        ));

        $scheduler = new Scheduler($registry);

        // Pick a time that is NOT 03:00 UTC (use 12:30 UTC)
        $now = new DateTimeImmutable('2025-06-15 12:30:00', new DateTimeZone('UTC'));
        $result = $scheduler->tick($now);

        self::assertTrue($everyMinuteRan);
        self::assertFalse($dailyRan);
        self::assertSame(1, $result->jobsDue);
        self::assertSame(1, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
    }

    #[Test]
    public function failedJobsAreReportedInTickResult(): void
    {
        $registry = new JobRegistry();

        $registry->register(new CallbackJob(
            name: 'good-job',
            schedule: Schedule::everyMinute(),
            callback: function (JobContext $ctx): string {
                return 'ok';
            },
            description: 'Succeeds',
        ));

        $registry->register(new CallbackJob(
            name: 'bad-job',
            schedule: Schedule::everyMinute(),
            callback: function (JobContext $ctx): string {
                throw new RuntimeException('Something went wrong');
            },
            description: 'Fails',
        ));

        $scheduler = new Scheduler($registry);
        $result = $scheduler->tick();

        self::assertSame(2, $result->jobsDue);
        self::assertSame(2, $result->jobsRun);
        self::assertSame(1, $result->jobsFailed);
        self::assertTrue($result->hasFailures());

        $failedResults = array_filter(
            $result->results,
            static fn(JobResult $r): bool => $r->status === JobStatus::Failure,
        );

        self::assertCount(1, $failedResults);
        $failedResult = array_values($failedResults)[0];
        self::assertSame('bad-job', $failedResult->jobName);
        self::assertNotNull($failedResult->exception);
        self::assertSame('Something went wrong', $failedResult->exception->getMessage());
    }

    #[Test]
    public function schedulerWithCallbackJobs(): void
    {
        $registry = new JobRegistry();

        $accumulator = [];

        $registry->register(new CallbackJob(
            name: 'collect-metrics',
            schedule: Schedule::everyMinute(),
            callback: function (JobContext $ctx) use (&$accumulator): string {
                $accumulator[] = 'metrics-collected-at-' . $ctx->scheduledAt->format('H:i');
                return 'metrics collected';
            },
            description: 'Collects system metrics',
        ));

        $registry->register(new CallbackJob(
            name: 'send-notifications',
            schedule: Schedule::everyMinute(),
            callback: function (JobContext $ctx) use (&$accumulator): string {
                $accumulator[] = 'notifications-sent';
                return 'notifications sent';
            },
            description: 'Sends pending notifications',
        ));

        $scheduler = new Scheduler($registry);
        $now = new DateTimeImmutable('2025-06-15 10:00:00', new DateTimeZone('UTC'));
        $result = $scheduler->tick($now);

        self::assertSame(2, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
        self::assertCount(2, $accumulator);
        self::assertStringContainsString('metrics-collected-at-', $accumulator[0]);
        self::assertSame('notifications-sent', $accumulator[1]);

        // Verify individual job results contain output
        foreach ($result->results as $jobResult) {
            self::assertSame(JobStatus::Success, $jobResult->status);
            self::assertNotEmpty($jobResult->output);
        }
    }
}
