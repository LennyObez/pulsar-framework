<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use function array_values;
use function count;

use Pulsar\Api\Api;
use Pulsar\Queue\Exception\QueueException;

use function time;

/**
 * Dead-letter queue for managing permanently failed jobs.
 *
 * Failed jobs are stored separately from the main queue for later
 * inspection, manual retry, or bulk purging.
 */
#[Api]
final class DeadLetterQueue
{
    /** @var array<string, FailedJob> */
    private array $failedJobs = [];

    public function __construct(
        private readonly QueueDriverInterface $driver,
    ) {}

    /**
     * Store a failed job record in the dead-letter queue.
     */
    public function store(JobRecord $record, string $exception): void
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
    }

    /**
     * Re-dispatch a single failed job back onto its original queue.
     *
     * @throws QueueException If the failed job ID is not found.
     */
    public function retry(string $failedJobId): void
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
    }

    /**
     * Re-dispatch all failed jobs back onto their original queues.
     *
     * @return int The number of jobs retried.
     */
    public function retryAll(): int
    {
        $count = count($this->failedJobs);

        foreach ($this->failedJobs as $failedJob) {
            $this->driver->push(
                $failedJob->queue,
                $failedJob->jobClass,
                $failedJob->payload,
            );
        }

        $this->failedJobs = [];

        return $count;
    }

    /**
     * Remove all failed jobs from the dead-letter queue.
     *
     * @return int The number of jobs purged.
     */
    public function purge(): int
    {
        $count = count($this->failedJobs);
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
