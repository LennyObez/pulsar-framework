<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Collector;

use function bin2hex;

use Closure;

use function count;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Scheduler\Exception\SchedulerException;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Scheduler;
use Pulsar\Scheduler\SchedulerTickResult;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\Payload\SchedulerRunPayload;
use Pulsar\Studio\CorrelationContext;
use Pulsar\Studio\FiberScopedContextProvider;

use function random_bytes;

use Throwable;

/**
 * Scheduler decorator that instruments job execution for Studio.
 *
 * For each job, creates a merged CorrelationContext with a new jobId
 * and inherited fields from the current scope (if any), enters a
 * fiber-local scope, and emits SchedulerRunPayload events.
 *
 * All collectors during job execution see the jobId via the shared
 * CorrelationContextProviderInterface.
 */
#[Internal]
final class InstrumentedScheduler implements CollectorInterface
{
    private bool $enabled = true;

    /**
     * @param Closure(ConsoleEvent, ?CorrelationContext): void $emit
     */
    public function __construct(
        private readonly Scheduler $inner,
        private readonly FiberScopedContextProvider $contextProvider,
        private readonly Closure $emit,
    ) {}

    /**
     * Execute a single tick: find and run all due jobs.
     *
     * @throws DateInvalidTimeZoneException
     * @throws SchedulerException
     */
    public function tick(?DateTimeImmutable $now = null): SchedulerTickResult
    {
        if (!$this->enabled) {
            return $this->inner->tick($now);
        }

        $now ??= new DateTimeImmutable();
        $dueJobs = $this->inner->registry()->dueJobs($now);
        $results = [];
        $failed = 0;

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
     * Run a specific job with correlation context and instrumentation.
     */
    public function runJob(JobInterface $job, ?DateTimeImmutable $scheduledAt = null): JobResult
    {
        if (!$this->enabled) {
            return $this->inner->runJob($job, $scheduledAt);
        }

        $existing = $this->contextProvider->current();
        $jobContext = new CorrelationContext(
            requestId: $existing?->requestId,
            traceId: $existing?->traceId,
            spanId: $existing?->spanId,
            jobId: bin2hex(random_bytes(16)),
        );

        $scope = $this->contextProvider->enter($jobContext);

        try {
            $result = $this->inner->runJob($job, $scheduledAt);

            $this->emitJobEvent($result, $jobContext);

            return $result;
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $scope->close();
        }
    }

    /**
     * Get the job registry.
     */
    public function registry(): JobRegistry
    {
        return $this->inner->registry();
    }

    /**
     * Get the underlying scheduler.
     */
    public function inner(): Scheduler
    {
        return $this->inner;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    private function emitJobEvent(JobResult $result, CorrelationContext $context): void
    {
        $event = new SchedulerRunPayload(
            jobName: $result->jobName,
            status: $result->status->value,
            durationMs: $result->durationMs(),
            errorMessage: $result->exception?->getMessage(),
        );

        try {
            ($this->emit)($event, $context);
        } catch (Throwable) {
        }
    }
}
