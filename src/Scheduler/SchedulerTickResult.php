<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Result of a single scheduler tick.
 */
#[Api(since: '1.0.0')]
readonly class SchedulerTickResult
{
    /**
     * @param list<JobResult> $results
     */
    public function __construct(
        public DateTimeImmutable $tickAt,
        public array $results,
        public int $jobsDue,
        public int $jobsRun,
        public int $jobsFailed,
    ) {}

    /**
     * Check if any jobs failed during this tick.
     */
    public function hasFailures(): bool
    {
        return $this->jobsFailed > 0;
    }
}
