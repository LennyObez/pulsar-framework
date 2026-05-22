<?php

declare(strict_types=1);

namespace Pulsar\Queue\Batch;

use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;

use function array_filter;
use function array_keys;
use function count;
use function time;

/**
 * In-memory batch repository for testing.
 *
 * State is not persisted across process boundaries.
 */
#[Internal(reason: 'Testing implementation; use BatchRepositoryInterface for type hints')]
final class InMemoryBatchRepository implements BatchRepositoryInterface
{
    /** @var array<string, JobBatch> */
    private array $batches = [];

    public function find(string $batchId): ?JobBatch
    {
        return $this->batches[$batchId] ?? null;
    }

    public function store(JobBatch $batch): void
    {
        $this->batches[$batch->id] = $batch;
    }

    public function markJobComplete(string $batchId): JobBatch
    {
        $batch = $this->resolve($batchId);

        $pendingJobs = $batch->pendingJobs - 1;
        $finishedAt = $pendingJobs === 0 ? time() : null;

        $updated = new JobBatch(
            id: $batch->id,
            name: $batch->name,
            totalJobs: $batch->totalJobs,
            pendingJobs: $pendingJobs,
            failedJobs: $batch->failedJobs,
            cancelled: $batch->cancelled,
            allowFailures: $batch->allowFailures,
            createdAt: $batch->createdAt,
            finishedAt: $finishedAt,
        );

        $this->batches[$batchId] = $updated;

        return $updated;
    }

    public function markJobFailed(string $batchId): JobBatch
    {
        $batch = $this->resolve($batchId);

        $pendingJobs = $batch->pendingJobs - 1;
        $failedJobs = $batch->failedJobs + 1;
        $finishedAt = $pendingJobs === 0 ? time() : null;

        $updated = new JobBatch(
            id: $batch->id,
            name: $batch->name,
            totalJobs: $batch->totalJobs,
            pendingJobs: $pendingJobs,
            failedJobs: $failedJobs,
            cancelled: $batch->cancelled,
            allowFailures: $batch->allowFailures,
            createdAt: $batch->createdAt,
            finishedAt: $finishedAt,
        );

        $this->batches[$batchId] = $updated;

        return $updated;
    }

    public function cancel(string $batchId): void
    {
        $batch = $this->resolve($batchId);

        $this->batches[$batchId] = new JobBatch(
            id: $batch->id,
            name: $batch->name,
            totalJobs: $batch->totalJobs,
            pendingJobs: $batch->pendingJobs,
            failedJobs: $batch->failedJobs,
            cancelled: true,
            allowFailures: $batch->allowFailures,
            createdAt: $batch->createdAt,
            finishedAt: $batch->finishedAt,
        );
    }

    public function prune(int $beforeTimestamp): int
    {
        $pruned = array_filter(
            $this->batches,
            static fn(JobBatch $b): bool => $b->finishedAt !== null && $b->finishedAt < $beforeTimestamp,
        );

        foreach (array_keys($pruned) as $id) {
            unset($this->batches[$id]);
        }

        return count($pruned);
    }

    /**
     * @throws QueueException If the batch does not exist.
     */
    private function resolve(string $batchId): JobBatch
    {
        $batch = $this->batches[$batchId] ?? null;

        if ($batch === null) {
            throw QueueException::jobNotFound($batchId);
        }

        return $batch;
    }
}
