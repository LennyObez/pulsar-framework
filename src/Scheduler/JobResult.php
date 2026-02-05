<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Throwable;

/**
 * Result of a scheduled job execution.
 */
#[Api]
readonly class JobResult
{
    public function __construct(
        public string $jobName,
        public JobStatus $status,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public string $output = '',
        public ?Throwable $exception = null,
    ) {}

    /**
     * Get the execution duration in milliseconds.
     */
    public function durationMs(): float
    {
        $start = (float) $this->startedAt->format('U.u');
        $end = (float) $this->finishedAt->format('U.u');

        return ($end - $start) * 1000.0;
    }

    /**
     * Create a success result.
     */
    public static function success(string $jobName, DateTimeImmutable $startedAt, string $output = ''): self
    {
        return new self(
            jobName: $jobName,
            status: JobStatus::Success,
            startedAt: $startedAt,
            finishedAt: new DateTimeImmutable(),
            output: $output,
        );
    }

    /**
     * Create a failure result.
     */
    public static function failure(string $jobName, DateTimeImmutable $startedAt, Throwable $exception): self
    {
        return new self(
            jobName: $jobName,
            status: JobStatus::Failure,
            startedAt: $startedAt,
            finishedAt: new DateTimeImmutable(),
            exception: $exception,
        );
    }

    /**
     * Create a skipped result.
     */
    public static function skipped(string $jobName): self
    {
        $now = new DateTimeImmutable();

        return new self(
            jobName: $jobName,
            status: JobStatus::Skipped,
            startedAt: $now,
            finishedAt: $now,
        );
    }
}
