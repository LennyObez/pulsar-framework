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
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Scheduler;
use Pulsar\Scheduler\SchedulerTickResult;
use RuntimeException;

#[CoversClass(Scheduler::class)]
#[CoversClass(SchedulerTickResult::class)]
final class SchedulerTest extends TestCase
{
    #[Test]
    public function tickRunsDueJobs(): void
    {
        $registry = new JobRegistry();
        $executed = false;

        $registry->register(new CallbackJob(
            name: 'due-job',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx) use (&$executed): string {
                $executed = true;

                return 'ran';
            },
        ));

        $scheduler = new Scheduler($registry);
        $result = $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        self::assertTrue($executed);
        self::assertSame(1, $result->jobsDue);
        self::assertSame(1, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
        self::assertCount(1, $result->results);
        self::assertSame(JobStatus::Success, $result->results[0]->status);
    }

    #[Test]
    public function tickSkipsNotDueJobs(): void
    {
        $registry = new JobRegistry();
        $executed = false;

        // Daily at midnight only
        $registry->register(new CallbackJob(
            name: 'midnight-job',
            schedule: Schedule::daily(),
            callback: static function (JobContext $ctx) use (&$executed): string {
                $executed = true;

                return 'ran';
            },
        ));

        $scheduler = new Scheduler($registry);
        // 09:30 is not midnight, so the job should not run
        $result = $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        self::assertFalse($executed);
        self::assertSame(0, $result->jobsDue);
        self::assertSame(0, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
        self::assertCount(0, $result->results);
    }

    #[Test]
    public function tickResultContainsCorrectCounts(): void
    {
        $registry = new JobRegistry();

        // Two every-minute jobs (always due)
        $registry->register(new CallbackJob(
            name: 'job-a',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'a',
        ));
        $registry->register(new CallbackJob(
            name: 'job-b',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'b',
        ));

        // One daily job (not due at 09:30)
        $registry->register(new CallbackJob(
            name: 'job-c',
            schedule: Schedule::daily(),
            callback: static fn(JobContext $ctx): string => 'c',
        ));

        $scheduler = new Scheduler($registry);
        $result = $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        self::assertSame(2, $result->jobsDue);
        self::assertSame(2, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
        self::assertCount(2, $result->results);
    }

    #[Test]
    public function tickCountsFailures(): void
    {
        $registry = new JobRegistry();

        $registry->register(new CallbackJob(
            name: 'good-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'ok',
        ));
        $registry->register(new CallbackJob(
            name: 'bad-job',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx): never {
                throw new RuntimeException('Boom');
            },
        ));

        $scheduler = new Scheduler($registry);
        $result = $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        self::assertSame(2, $result->jobsDue);
        self::assertSame(2, $result->jobsRun);
        self::assertSame(1, $result->jobsFailed);
        self::assertTrue($result->hasFailures());
    }

    #[Test]
    public function hasFailuresReturnsFalseWhenAllSucceed(): void
    {
        $registry = new JobRegistry();

        $registry->register(new CallbackJob(
            name: 'ok-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'ok',
        ));

        $scheduler = new Scheduler($registry);
        $result = $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        self::assertFalse($result->hasFailures());
    }

    #[Test]
    public function tickSetsTickAtTime(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);

        $now = new DateTimeImmutable('2026-01-05 09:30:00');
        $result = $scheduler->tick($now);

        self::assertSame($now, $result->tickAt);
    }

    #[Test]
    public function registryAccessor(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);

        self::assertSame($registry, $scheduler->registry());
    }
}
