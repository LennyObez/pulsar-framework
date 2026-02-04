<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use function count;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Scheduler\Exception\SchedulerException;

use function sprintf;

/**
 * Job scheduler that evaluates due jobs and executes them.
 */
final readonly class Scheduler
{
    public function __construct(
        private JobRegistry $registry,
        private ?LoggerInterface $logger = null,
        private ?MetricRegistry $metrics = null,
    ) {}

    /**
     * Execute a single tick: find and run all due jobs.
     *
     * @throws DateInvalidTimeZoneException If a job's schedule has an invalid timezone
     * @throws SchedulerException If a job's cron expression is invalid
     */
    public function tick(?DateTimeImmutable $now = null): SchedulerTickResult
    {
        $now ??= new DateTimeImmutable();
        $dueJobs = $this->registry->dueJobs($now);
        $results = [];
        $failed = 0;

        $this->logger?->info(sprintf('Scheduler tick: %d job(s) due', count($dueJobs)));

        foreach ($dueJobs as $job) {
            $result = $this->runJob($job, $now);
            $results[] = $result;

            if ($result->status === JobStatus::Failure) {
                $failed++;
            }
        }

        return new SchedulerTickResult(
            tickAt: $now,
            results: $results,
            jobsDue: count($dueJobs),
            jobsRun: count($results),
            jobsFailed: $failed,
        );
    }

    /**
     * Run a specific job.
     */
    public function runJob(JobInterface $job, ?DateTimeImmutable $scheduledAt = null): JobResult
    {
        $scheduledAt ??= new DateTimeImmutable();
        $startedAt = new DateTimeImmutable();

        $context = new JobContext(
            scheduledAt: $scheduledAt,
            startedAt: $startedAt,
            logger: $this->logger,
            metrics: $this->metrics,
        );

        $this->logger?->info(sprintf('Executing job: %s', $job->getName()));

        $result = $job->execute($context);

        if ($result->status === JobStatus::Failure) {
            $this->logger?->error(sprintf(
                'Job "%s" failed: %s',
                $job->getName(),
                $result->exception?->getMessage() ?? 'unknown error',
            ));
        } else {
            $this->logger?->info(sprintf(
                'Job "%s" completed in %.1fms',
                $job->getName(),
                $result->durationMs(),
            ));
        }

        return $result;
    }

    /**
     * Get the job registry.
     */
    public function registry(): JobRegistry
    {
        return $this->registry;
    }
}
