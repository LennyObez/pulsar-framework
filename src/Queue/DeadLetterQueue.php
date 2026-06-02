<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Queue\Driver\InMemoryFailedJobRepository;
use Pulsar\Queue\Event\DlqJobDeleted;
use Pulsar\Queue\Event\DlqJobInspected;
use Pulsar\Queue\Event\DlqJobRetried;
use Pulsar\Queue\Event\DlqJobStored;
use Pulsar\Queue\Exception\QueueException;

use function time;
use function trim;

/**
 * Dead-letter queue for managing permanently failed jobs.
 *
 * Failed jobs are stored separately from the main queue for later
 * inspection, manual retry, or bulk purging. All mutation operations
 * emit audit events via the event dispatcher.
 *
 * Storage is delegated to a {@see FailedJobRepositoryInterface} so dead-lettered
 * jobs survive worker restarts; without a durable repository the audit/retention
 * guarantee would be lost. When none is supplied a process-local in-memory
 * repository is used (suitable for tests and sync/memory transports).
 * @api
 */
#[Api(since: '1.0.0')]
final class DeadLetterQueue
{
    private readonly FailedJobRepositoryInterface $repository;

    /**
     * @param QueueDriverInterface $driver The live queue transport, used only to
     *        re-dispatch jobs on retry — distinct from the repository, which is
     *        the durable dead-letter metadata store.
     */
    public function __construct(
        private readonly QueueDriverInterface $driver,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly bool $regulated = false,
        ?FailedJobRepositoryInterface $repository = null,
    ) {
        $this->repository = $repository ?? new InMemoryFailedJobRepository();
    }

    /**
     * Store a failed job record in the dead-letter queue.
     */
    public function store(JobRecord $record, string $exception, string $correlationId = ''): void
    {
        $this->repository->store(new FailedJob(
            id: $record->id,
            queue: $record->queue,
            jobClass: $record->jobClass,
            payload: $record->payload,
            exception: $exception,
            failedAt: time(),
            attempts: $record->attempts,
        ));

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
        $failedJob = $this->repository->find($failedJobId);

        if ($failedJob === null) {
            throw QueueException::jobNotFound($failedJobId);
        }

        $this->driver->push(
            $failedJob->queue,
            $failedJob->jobClass,
            $failedJob->payload,
        );

        $this->repository->forget($failedJobId);

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
     * Each job is removed from the repository immediately after it is
     * successfully re-dispatched, so if a push fails part-way the already
     * re-dispatched jobs are gone (they are back on the live queue) while the
     * remainder stay dead-lettered for a later retry.
     *
     * @return int The number of jobs successfully re-dispatched.
     */
    public function retryAll(string $actorId = '', string $correlationId = ''): int
    {
        $count = 0;

        foreach ($this->repository->all() as $failedJob) {
            $this->driver->push(
                $failedJob->queue,
                $failedJob->jobClass,
                $failedJob->payload,
            );

            $this->repository->forget($failedJob->id);

            $this->eventDispatcher?->dispatch(new DlqJobRetried(
                jobId: $failedJob->id,
                actorId: $actorId,
                correlationId: $correlationId,
                timestamp: time(),
            ));

            $count++;
        }

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
        // Regulated reason-check must precede the existence check so that a
        // missing reason yields the compliance error rather than leaking whether
        // the job id exists via error-message discrimination.
        if ($this->regulated && trim($reason) === '') {
            throw QueueException::deleteReasonRequired($failedJobId);
        }

        if (!$this->repository->forget($failedJobId)) {
            throw QueueException::jobNotFound($failedJobId);
        }

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
        $failedJob = $this->repository->find($failedJobId);

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

        $auditReason = trim($reason) === '' ? 'Bulk purge' : $reason;

        foreach ($this->repository->all() as $failedJob) {
            $this->eventDispatcher?->dispatch(new DlqJobDeleted(
                jobId: $failedJob->id,
                actorId: $actorId,
                reason: $auditReason,
                correlationId: $correlationId,
                timestamp: time(),
            ));
        }

        return $this->repository->flush();
    }

    /**
     * List all failed jobs currently in the dead-letter queue.
     *
     * @return list<FailedJob>
     */
    public function list(): array
    {
        return $this->repository->all();
    }
}
