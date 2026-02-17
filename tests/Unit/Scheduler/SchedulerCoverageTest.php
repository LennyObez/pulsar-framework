<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
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
#[CoversClass(Schedule::class)]
#[CoversClass(CallbackJob::class)]
#[CoversClass(JobResult::class)]
#[CoversClass(SchedulerTickResult::class)]
final class SchedulerCoverageTest extends TestCase
{
    #[Test]
    public function tickWithNoDueJobsReturnsEmptyResult(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);

        // Use a time that doesn't match any scheduled job
        $now = new DateTimeImmutable('2026-01-15 12:30:00');
        $result = $scheduler->tick($now);

        self::assertSame(0, $result->jobsDue);
        self::assertSame(0, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
        self::assertSame([], $result->results);
    }

    #[Test]
    public function tickRunsDueJobs(): void
    {
        $registry = new JobRegistry();
        $ran = false;

        $job = new CallbackJob(
            name: 'test-job',
            schedule: Schedule::everyMinute(),
            callback: function (JobContext $ctx) use (&$ran): string {
                $ran = true;
                return 'done';
            },
        );

        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $result = $scheduler->tick();

        self::assertTrue($ran);
        self::assertSame(1, $result->jobsDue);
        self::assertSame(1, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
    }

    #[Test]
    public function tickCountsFailedJobs(): void
    {
        $registry = new JobRegistry();

        $job = new CallbackJob(
            name: 'failing-job',
            schedule: Schedule::everyMinute(),
            callback: function (): never {
                throw new RuntimeException('job failed');
            },
        );

        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $result = $scheduler->tick();

        self::assertSame(1, $result->jobsDue);
        self::assertSame(1, $result->jobsRun);
        self::assertSame(1, $result->jobsFailed);

        self::assertSame(JobStatus::Failure, $result->results[0]->status);
        self::assertNotNull($result->results[0]->exception);
    }

    #[Test]
    public function runJobReturnsSuccessResult(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);

        $job = new CallbackJob(
            name: 'direct-run',
            schedule: Schedule::daily(),
            callback: fn(): string => 'executed',
        );

        $result = $scheduler->runJob($job);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('direct-run', $result->jobName);
        self::assertSame('executed', $result->output);
        self::assertGreaterThanOrEqual(0.0, $result->durationMs());
    }

    #[Test]
    public function runJobReturnsFailureOnException(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);

        $job = new CallbackJob(
            name: 'error-job',
            schedule: Schedule::daily(),
            callback: fn(): never => throw new RuntimeException('broken'),
        );

        $result = $scheduler->runJob($job);

        self::assertSame(JobStatus::Failure, $result->status);
        self::assertSame('error-job', $result->jobName);
        self::assertNotNull($result->exception);
        self::assertSame('broken', $result->exception->getMessage());
    }

    #[Test]
    public function registryAccessor(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);

        self::assertSame($registry, $scheduler->registry());
    }

    // ── Schedule convenience methods ────────────────────────────────────

    #[Test]
    public function scheduleEveryMinuteMatchesAnyTime(): void
    {
        $schedule = Schedule::everyMinute();
        self::assertSame('* * * * *', $schedule->expression);
        self::assertSame('UTC', $schedule->timezone);
    }

    #[Test]
    public function scheduleEveryFiveMinutes(): void
    {
        $schedule = Schedule::everyFiveMinutes();
        self::assertSame('*/5 * * * *', $schedule->expression);
    }

    #[Test]
    public function scheduleHourly(): void
    {
        $schedule = Schedule::hourly();
        self::assertSame('0 * * * *', $schedule->expression);
    }

    #[Test]
    public function scheduleDaily(): void
    {
        $schedule = Schedule::daily();
        self::assertSame('0 0 * * *', $schedule->expression);
    }

    #[Test]
    public function scheduleDailyAt(): void
    {
        $schedule = Schedule::dailyAt('14:30');
        self::assertSame('30 14 * * *', $schedule->expression);
    }

    #[Test]
    public function scheduleWeekly(): void
    {
        $schedule = Schedule::weekly();
        self::assertSame('0 0 * * 0', $schedule->expression);
    }

    #[Test]
    public function scheduleMonthly(): void
    {
        $schedule = Schedule::monthly();
        self::assertSame('0 0 1 * *', $schedule->expression);
    }

    #[Test]
    public function scheduleCron(): void
    {
        $schedule = Schedule::cron('15 3 * * 1', 'America/New_York');
        self::assertSame('15 3 * * 1', $schedule->expression);
        self::assertSame('America/New_York', $schedule->timezone);
    }

    #[Test]
    public function scheduleWithTimezone(): void
    {
        $schedule = Schedule::everyMinute('Europe/Paris');
        self::assertSame('Europe/Paris', $schedule->timezone);
    }

    // ── CallbackJob ─────────────────────────────────────────────────────

    #[Test]
    public function callbackJobGetName(): void
    {
        $job = new CallbackJob('my-job', Schedule::daily(), fn(): string => 'ok');
        self::assertSame('my-job', $job->getName());
    }

    #[Test]
    public function callbackJobGetSchedule(): void
    {
        $schedule = Schedule::hourly();
        $job = new CallbackJob('job', $schedule, fn(): string => 'ok');
        self::assertSame($schedule, $job->getSchedule());
    }

    #[Test]
    public function callbackJobGetDescription(): void
    {
        $job = new CallbackJob('job', Schedule::daily(), fn(): string => 'ok', 'A daily cleanup');
        self::assertSame('A daily cleanup', $job->getDescription());
    }

    #[Test]
    public function callbackJobDescriptionDefaultsToEmpty(): void
    {
        $job = new CallbackJob('job', Schedule::daily(), fn(): string => 'ok');
        self::assertSame('', $job->getDescription());
    }

    #[Test]
    public function callbackJobReturnsNullOutputAsEmptyString(): void
    {
        $job = new CallbackJob('job', Schedule::daily(), fn(): ?string => null);

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('', $result->output);
    }

    // ── SchedulerTickResult ─────────────────────────────────────────────

    #[Test]
    public function schedulerTickResultProperties(): void
    {
        $now = new DateTimeImmutable();
        $result = new SchedulerTickResult(
            tickAt: $now,
            results: [],
            jobsDue: 5,
            jobsRun: 3,
            jobsFailed: 1,
        );

        self::assertSame($now, $result->tickAt);
        self::assertSame(5, $result->jobsDue);
        self::assertSame(3, $result->jobsRun);
        self::assertSame(1, $result->jobsFailed);
        self::assertSame([], $result->results);
    }
}
