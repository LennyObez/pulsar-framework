<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\CallbackJob;
use Pulsar\Scheduler\Exception\SchedulerException;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Schedule;

#[CoversClass(JobRegistry::class)]
final class JobRegistryTest extends TestCase
{
    #[Test]
    public function registerAndGetJob(): void
    {
        $registry = new JobRegistry();
        $job = new CallbackJob(
            name: 'test-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'done',
        );

        $registry->register($job);
        $retrieved = $registry->get('test-job');

        self::assertSame($job, $retrieved);
    }

    #[Test]
    public function hasReturnsTrueForRegisteredJob(): void
    {
        $registry = new JobRegistry();
        $job = new CallbackJob(
            name: 'existing-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'done',
        );

        $registry->register($job);

        self::assertTrue($registry->has('existing-job'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredJob(): void
    {
        $registry = new JobRegistry();

        self::assertFalse($registry->has('nonexistent-job'));
    }

    #[Test]
    public function allReturnsAllRegisteredJobs(): void
    {
        $registry = new JobRegistry();
        $job1 = new CallbackJob(
            name: 'job-one',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'one',
        );
        $job2 = new CallbackJob(
            name: 'job-two',
            schedule: Schedule::hourly(),
            callback: static fn(JobContext $ctx): string => 'two',
        );

        $registry->register($job1);
        $registry->register($job2);

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('job-one', $all);
        self::assertArrayHasKey('job-two', $all);
        self::assertSame($job1, $all['job-one']);
        self::assertSame($job2, $all['job-two']);
    }

    #[Test]
    public function allReturnsEmptyArrayWhenNoJobsRegistered(): void
    {
        $registry = new JobRegistry();

        self::assertSame([], $registry->all());
    }

    #[Test]
    public function countReturnsCorrectCount(): void
    {
        $registry = new JobRegistry();

        self::assertSame(0, $registry->count());

        $registry->register(new CallbackJob(
            name: 'job-one',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'one',
        ));

        self::assertSame(1, $registry->count());

        $registry->register(new CallbackJob(
            name: 'job-two',
            schedule: Schedule::hourly(),
            callback: static fn(JobContext $ctx): string => 'two',
        ));

        self::assertSame(2, $registry->count());
    }

    #[Test]
    public function dueJobsReturnsOnlyDueJobs(): void
    {
        $registry = new JobRegistry();

        // This job runs every minute, so always due
        $alwaysDueJob = new CallbackJob(
            name: 'always-due',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'due',
        );

        // This job runs daily at midnight only
        $dailyJob = new CallbackJob(
            name: 'daily-only',
            schedule: Schedule::daily(),
            callback: static fn(JobContext $ctx): string => 'daily',
        );

        $registry->register($alwaysDueJob);
        $registry->register($dailyJob);

        // At 09:30, only the every-minute job should be due
        $now = new DateTimeImmutable('2026-01-05 09:30:00');
        $dueJobs = $registry->dueJobs($now);

        self::assertCount(1, $dueJobs);
        self::assertSame('always-due', $dueJobs[0]->getName());
    }

    #[Test]
    public function dueJobsReturnsMultipleJobsWhenAllDue(): void
    {
        $registry = new JobRegistry();

        $registry->register(new CallbackJob(
            name: 'job-a',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'a',
        ));
        $registry->register(new CallbackJob(
            name: 'job-b',
            schedule: Schedule::daily(),
            callback: static fn(JobContext $ctx): string => 'b',
        ));

        // At midnight, both should be due
        $now = new DateTimeImmutable('2026-01-05 00:00:00');
        $dueJobs = $registry->dueJobs($now);

        self::assertCount(2, $dueJobs);
    }

    #[Test]
    public function duplicateRegistrationThrowsSchedulerException(): void
    {
        $registry = new JobRegistry();
        $job = new CallbackJob(
            name: 'duplicate-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'done',
        );

        $registry->register($job);

        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessageIsOrContains('A job named "duplicate-job" is already registered');

        $duplicate = new CallbackJob(
            name: 'duplicate-job',
            schedule: Schedule::hourly(),
            callback: static fn(JobContext $ctx): string => 'other',
        );
        $registry->register($duplicate);
    }

    #[Test]
    public function getForMissingJobThrowsSchedulerException(): void
    {
        $registry = new JobRegistry();

        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessageIsOrContains('Scheduled job not found: "nonexistent"');

        $_ = $registry->get('nonexistent');
    }
}
