<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\JobStatus;
use RuntimeException;

#[CoversClass(JobResult::class)]
final class JobResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesSuccessResult(): void
    {
        $startedAt = new DateTimeImmutable('2026-01-05 09:00:00');
        $result = JobResult::success('my-job', $startedAt, 'output text');

        self::assertSame('my-job', $result->jobName);
        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('output text', $result->output);
        self::assertNull($result->exception);
        self::assertSame($startedAt, $result->startedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $result->finishedAt);
    }

    #[Test]
    public function successFactoryWithEmptyOutput(): void
    {
        $startedAt = new DateTimeImmutable('2026-01-05 09:00:00');
        $result = JobResult::success('my-job', $startedAt);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('', $result->output);
    }

    #[Test]
    public function failureFactoryCreatesFailureResult(): void
    {
        $startedAt = new DateTimeImmutable('2026-01-05 09:00:00');
        $exception = new RuntimeException('Job failed hard');
        $result = JobResult::failure('broken-job', $startedAt, $exception);

        self::assertSame('broken-job', $result->jobName);
        self::assertSame(JobStatus::Failure, $result->status);
        self::assertSame($exception, $result->exception);
        self::assertSame($startedAt, $result->startedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $result->finishedAt);
    }

    #[Test]
    public function skippedFactoryCreatesSkippedResult(): void
    {
        $result = JobResult::skipped('skipped-job');

        self::assertSame('skipped-job', $result->jobName);
        self::assertSame(JobStatus::Skipped, $result->status);
        self::assertSame('', $result->output);
        self::assertNull($result->exception);
        self::assertInstanceOf(DateTimeImmutable::class, $result->startedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $result->finishedAt);
    }

    #[Test]
    public function durationMsCalculation(): void
    {
        $startedAt = new DateTimeImmutable('2026-01-05 09:00:00.000000');
        $finishedAt = new DateTimeImmutable('2026-01-05 09:00:01.500000');

        $result = new JobResult(
            jobName: 'timed-job',
            status: JobStatus::Success,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            output: '',
        );

        self::assertEqualsWithDelta(1500.0, $result->durationMs(), 0.1);
    }

    #[Test]
    public function durationMsWithZeroDuration(): void
    {
        $time = new DateTimeImmutable('2026-01-05 09:00:00.000000');

        $result = new JobResult(
            jobName: 'instant-job',
            status: JobStatus::Success,
            startedAt: $time,
            finishedAt: $time,
            output: '',
        );

        self::assertEqualsWithDelta(0.0, $result->durationMs(), 0.1);
    }

    #[Test]
    public function durationMsWithSubMillisecondPrecision(): void
    {
        $startedAt = new DateTimeImmutable('2026-01-05 09:00:00.000000');
        $finishedAt = new DateTimeImmutable('2026-01-05 09:00:00.250000');

        $result = new JobResult(
            jobName: 'fast-job',
            status: JobStatus::Success,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            output: '',
        );

        self::assertEqualsWithDelta(250.0, $result->durationMs(), 0.1);
    }
}
