<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use function array_filter;
use function array_values;
use function count;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Scheduler\Exception\SchedulerException;

/**
 * Registry of scheduled jobs.
 */
#[Api]
final class JobRegistry
{
    /** @var array<string, JobInterface> */
    private array $jobs = [];

    /**
     * Register a job.
     *
     * @throws SchedulerException If a job with the same name is already registered.
     */
    public function register(JobInterface $job): void
    {
        if (isset($this->jobs[$job->getName()])) {
            throw SchedulerException::duplicateJob($job->getName());
        }

        $this->jobs[$job->getName()] = $job;
    }

    /**
     * Get a job by name.
     *
     * @throws SchedulerException If the job is not found.
     */
    #[NoDiscard]
    public function get(string $name): JobInterface
    {
        if (!isset($this->jobs[$name])) {
            throw SchedulerException::jobNotFound($name);
        }

        return $this->jobs[$name];
    }

    /**
     * Get all registered jobs.
     *
     * @return array<string, JobInterface>
     */
    public function all(): array
    {
        return $this->jobs;
    }

    /**
     * Check if a job is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->jobs[$name]);
    }

    /**
     * Get all jobs that are due at the given time.
     *
     * @return list<JobInterface>
     *
     * @throws DateInvalidTimeZoneException If a job's schedule has an invalid timezone
     * @throws SchedulerException If a job's cron expression is invalid
     */
    public function dueJobs(DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->jobs,
            static fn(JobInterface $job): bool => $job->getSchedule()->isDue($now),
        ));
    }

    /**
     * Get the number of registered jobs.
     */
    public function count(): int
    {
        return count($this->jobs);
    }
}
