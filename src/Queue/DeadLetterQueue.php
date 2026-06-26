<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Queue\Event\DlqJobDeleted;
use Pulsar\Queue\Event\DlqJobInspected;
use Pulsar\Queue\Event\DlqJobRetried;
use Pulsar\Queue\Event\DlqJobStored;
use Pulsar\Queue\Exception\QueueException;

use function array_values;
use function count;
use function time;
use function trim;

/**
 * Dead-letter queue for managing permanently failed jobs.
 *
 * Failed jobs are stored separately from the main queue for later
 * inspection, manual retry, or bulk purging. All mutation operations
 * emit audit events via the event dispatcher.
 * @api
 */
#[Api(since: '1.0.0')]
final class DeadLetterQueue
{
    /** @var array<string, FailedJob> */
    private array $failedJobs = [];

    public function __construct(
        private readonly QueueDriverInterface $driver,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly bool $regulated = false,
    ) {}

    /**
     * Store a failed job record in the dead-letter queue.
     */
    public function store(JobRecord $record, string $exception, string $correlationId = ''): void
    {
        $failedJob = new FailedJob(
            id: $record->id,
            queue: $record->queue,
            jobClass: $record->jobClass,
            payload: $record->payload,
            exception: $exception,
            failedAt: time(),
            attempts: $record->attempts,
        );

        $this->failedJobs[$record->id] = $failedJob;

        $this->eventDispatcher?->dispatch(new DlqJobStored(
            jobId: $record->id,
            queue: $record->queue,
            jobClass: $record->jobClass,
            reason: $exception,
            correlationId: $correlationId,
            timestamp: time(),
        ));
    }

    /**
     * Re-dispatch a single failed job back onto its original queue.
     *
     * @throws QueueException If the failed job ID is not found.
     */
    public function retry(string $failedJobId, string $actorId = '', string $correlationId = ''): void
    {
        if (!isset($this->failedJobs[$failedJobId])) {
            throw QueueException::jobNotFound($failedJobId);
        }

        $failedJob = $this->failedJobs[$failedJobId];

        $this->driver->push(
            $failedJob->queue,
            $failedJob->jobClass,
            $failedJob->payload,
        );

        unset($this->failedJobs[$failedJobId]);

        $this->eventDispatcher?->dispatch(new DlqJobRetried(
            jobId: $failedJobId,
            actorId: $actorId,
            correlationId: $correlationId,
            timestamp: time(),
        ));
    }

    /**
     * Re-dispatch all failed jobs back onto their original queues.
     *
     * @return int The number of jobs retried.
     */
    public function retryAll(string $actorId = '', string $correlationId = ''): int
    {
        $count = count($this->failedJobs);

        foreach ($this->failedJobs as $failedJob) {
            $this->driver->push(
                $failedJob->queue,
                $failedJob->jobClass,
                $failedJob->payload,
            );

            $this->eventDispatcher?->dispatch(new DlqJobRetried(
                jobId: $failedJob->id,
                actorId: $actorId,
                correlationId: $correlationId,
                timestamp: time(),
            ));
        }

        $this->failedJobs = [];

        return $count;
    }

    /**
     * Delete a single failed job from the dead-letter queue.
     *
     * In regulated mode, a non-empty reason is mandatory.
     *
     * @throws QueueException If the failed job ID is not found or if a reason is required.
     */
    public function delete(string $failedJobId, string $reason, string $actorId = '', string $correlationId = ''): void
    {
        if ($this->regulated && trim($reason) === '') {
            throw QueueException::deleteReasonRequired($failedJobId);
        }

        if (!isset($this->failedJobs[$failedJobId])) {
            throw QueueException::jobNotFound($failedJobId);
        }

        unset($this->failedJobs[$failedJobId]);

        $this->eventDispatcher?->dispatch(new DlqJobDeleted(
            jobId: $failedJobId,
            actorId: $actorId,
            reason: $reason,
            correlationId: $correlationId,
            timestamp: time(),
        ));
    }

    /**
     * Inspect a failed job without modifying it.
     *
     * Returns the failed job if found, or null if no job exists with the given ID.
     * Emits an audit event when an event dispatcher is configured.
     */
    public function inspect(string $failedJobId, string $actorId = '', string $correlationId = ''): ?FailedJob
    {
        $failedJob = $this->failedJobs[$failedJobId] ?? null;

        if ($failedJob !== null) {
            $this->eventDispatcher?->dispatch(new DlqJobInspected(
                jobId: $failedJobId,
                actorId: $actorId,
                correlationId: $correlationId,
                timestamp: time(),
            ));
        }

        return $failedJob;
    }

    /**
     * Remove all failed jobs from the dead-letter queue.
     *
     * In regulated mode, a non-empty reason is mandatory — mirroring
     * {@see self::delete()} — so that bulk removals remain auditable.
     *
     * @return int The number of jobs purged.
     *
     * @throws QueueException If a reason is required but not provided.
     */
    public function purge(string $actorId = '', string $correlationId = '', string $reason = ''): int
    {
        if ($this->regulated && trim($reason) === '') {
            throw QueueException::purgeReasonRequired();
        }

        $count = count($this->failedJobs);
        $auditReason = trim($reason) === '' ? 'Bulk purge' : $reason;

        foreach ($this->failedJobs as $failedJob) {
            $this->eventDispatcher?->dispatch(new DlqJobDeleted(
                jobId: $failedJob->id,
                actorId: $actorId,
                reason: $auditReason,
                correlationId: $correlationId,
                timestamp: time(),
            ));
        }

        $this->failedJobs = [];

        return $count;
    }

    /**
     * List all failed jobs currently in the dead-letter queue.
     *
     * @return list<FailedJob>
     */
    public function list(): array
    {
        return array_values($this->failedJobs);
    }
}
