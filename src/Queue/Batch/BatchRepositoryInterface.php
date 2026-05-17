<?php

declare(strict_types=1);

namespace Pulsar\Queue\Batch;

use Pulsar\Api\Api;

/**
 * Contract for batch state persistence.
 *
 * Implementations must provide atomic updates for concurrent job completion
 * tracking within a batch.
 * @api
 */
#[Api(since: '1.0.0')]
interface BatchRepositoryInterface
{
    /**
     * Find a batch by its identifier.
     */
    public function find(string $batchId): ?JobBatch;

    /**
     * Persist a new batch record.
     */
    public function store(JobBatch $batch): void;

    /**
     * Mark a job within the batch as completed.
     *
     * Decrements the pending count. If the batch reaches zero pending jobs,
     * the finished timestamp should be set.
     */
    public function markJobComplete(string $batchId): JobBatch;

    /**
     * Mark a job within the batch as failed.
     *
     * Increments the failed count and decrements the pending count.
     * If the batch reaches zero pending jobs, the finished timestamp should be set.
     */
    public function markJobFailed(string $batchId): JobBatch;

    /**
     * Cancel a batch, preventing further processing of pending jobs.
     */
    public function cancel(string $batchId): void;

    /**
     * Remove batches that finished before the given Unix timestamp.
     *
     * @return int The number of batches pruned.
     */
    public function prune(int $beforeTimestamp): int;
}
