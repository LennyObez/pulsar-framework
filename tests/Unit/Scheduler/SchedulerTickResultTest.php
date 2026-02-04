<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\SchedulerTickResult;

#[CoversClass(SchedulerTickResult::class)]
final class SchedulerTickResultTest extends TestCase
{
    #[Test]
    public function constructionAndFieldAccess(): void
    {
        $tickAt = new DateTimeImmutable('2026-01-05 09:30:00');

        $jobResult = new JobResult(
            jobName: 'test-job',
            status: JobStatus::Success,
            startedAt: new DateTimeImmutable('2026-01-05 09:30:00'),
            finishedAt: new DateTimeImmutable('2026-01-05 09:30:01'),
            output: 'done',
        );

        $result = new SchedulerTickResult(
            tickAt: $tickAt,
            results: [$jobResult],
            jobsDue: 2,
            jobsRun: 1,
            jobsFailed: 0,
        );

        self::assertSame($tickAt, $result->tickAt);
        self::assertCount(1, $result->results);
        self::assertSame($jobResult, $result->results[0]);
        self::assertSame(2, $result->jobsDue);
        self::assertSame(1, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
    }

    #[Test]
    public function hasFailuresReturnsTrueWhenJobsFailedGreaterThanZero(): void
    {
        $result = new SchedulerTickResult(
            tickAt: new DateTimeImmutable('2026-01-05 09:30:00'),
            results: [],
            jobsDue: 3,
            jobsRun: 3,
            jobsFailed: 1,
        );

        self::assertTrue($result->hasFailures());
    }

    #[Test]
    public function hasFailuresReturnsTrueWithMultipleFailures(): void
    {
        $result = new SchedulerTickResult(
            tickAt: new DateTimeImmutable('2026-01-05 09:30:00'),
            results: [],
            jobsDue: 5,
            jobsRun: 5,
            jobsFailed: 3,
        );

        self::assertTrue($result->hasFailures());
    }

    #[Test]
    public function hasFailuresReturnsFalseWhenJobsFailedIsZero(): void
    {
        $result = new SchedulerTickResult(
            tickAt: new DateTimeImmutable('2026-01-05 09:30:00'),
            results: [],
            jobsDue: 2,
            jobsRun: 2,
            jobsFailed: 0,
        );

        self::assertFalse($result->hasFailures());
    }

    #[Test]
    public function emptyTickResult(): void
    {
        $result = new SchedulerTickResult(
            tickAt: new DateTimeImmutable('2026-01-05 09:30:00'),
            results: [],
            jobsDue: 0,
            jobsRun: 0,
            jobsFailed: 0,
        );

        self::assertFalse($result->hasFailures());
        self::assertSame(0, $result->jobsDue);
        self::assertSame(0, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
        self::assertCount(0, $result->results);
    }
}
