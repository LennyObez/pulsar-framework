<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use function count;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Scheduler\Exception\SchedulerException;
use Random\Randomizer;

use function sprintf;

/**
 * Job scheduler that evaluates due jobs and executes them.
 */
#[Api(since: '1.0.0')]
final readonly class Scheduler
{
    public function __construct(
        private JobRegistry $registry,
        private ?LoggerInterface $logger = null,
        private ?MetricRegistry $metrics = null,
        private ?RequestContextHolder $contextHolder = null,
        private ?Randomizer $randomizer = null,
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
     *
     * Creates a fresh RequestContext per job execution for correlation tracking.
     * The context is set in the holder for the duration of execution and cleaned
     * up in the finally block.
     */
    public function runJob(JobInterface $job, ?DateTimeImmutable $scheduledAt = null): JobResult
    {
        $scheduledAt ??= new DateTimeImmutable();
        $startedAt = new DateTimeImmutable();

        $requestContext = $this->createJobRequestContext();

        if ($requestContext !== null) {
            $this->contextHolder?->set($requestContext);
        }

        $context = new JobContext(
            scheduledAt: $scheduledAt,
            startedAt: $startedAt,
            logger: $this->logger,
            metrics: $this->metrics,
            requestContext: $requestContext,
        );

        $this->logger?->info(sprintf('Executing job: %s', $job->getName()));

        try {
            $result = $job->execute($context);
        } finally {
            $this->contextHolder?->clear();
        }

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
     * Create a fresh RequestContext for a scheduled job.
     *
     * Each scheduled job gets its own CorrelationId and CausationId for
     * independent tracking in audit logs and downstream operations.
     */
    private function createJobRequestContext(): ?RequestContext
    {
        if ($this->contextHolder === null) {
            return null;
        }

        return new RequestContext(
            correlationId: CorrelationId::generate($this->randomizer),
            causationId: CausationId::generate($this->randomizer),
        );
    }

    /**
     * Get the job registry.
     */
    public function registry(): JobRegistry
    {
        return $this->registry;
    }
}
