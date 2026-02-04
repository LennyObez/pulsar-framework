<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Contract for queue storage backends.
 *
 * Implementations are responsible for persisting, retrieving, and managing
 * job records in the underlying transport (database, memory, sync, etc.).
 */
#[Api(since: '1.0.0')]
interface QueueDriverInterface
{
    /**
     * Push a new job onto the given queue.
     *
     * @param string $queue    The target queue name.
     * @param string $jobClass Fully-qualified class name of the job.
     * @param string $payload  Serialized job payload.
     * @param int    $delay    Delay in seconds before the job becomes available.
     *
     * @return string The unique identifier assigned to the job.
     */
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string;

    /**
     * Pop the next available job from the given queue.
     *
     * Returns null when the queue is empty or no jobs are currently available.
     */
    public function pop(string $queue): ?JobRecord;

    /**
     * Acknowledge successful processing of a job.
     */
    public function acknowledge(string $jobId): void;

    /**
     * Reject a job, marking it as failed with the given reason.
     */
    public function reject(string $jobId, string $reason): void;

    /**
     * Get the number of pending jobs in the given queue.
     */
    public function size(string $queue): int;

    /**
     * Remove all jobs from the given queue.
     *
     * @return int The number of jobs that were purged.
     */
    public function purge(string $queue): int;

    /**
     * Retrieve all job records matching a given status.
     *
     * @return list<JobRecord>
     */
    public function findByStatus(JobRecordStatus $status): array;
}
