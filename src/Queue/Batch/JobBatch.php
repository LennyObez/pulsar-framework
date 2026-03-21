<?php

declare(strict_types=1);

namespace Pulsar\Queue\Batch;

use Pulsar\Api\Api;

/**
 * Immutable snapshot of an active job batch.
 *
 * Tracks the aggregate state of all jobs dispatched as part of a batch,
 * including pending/failed counts and cancellation status.
 */
#[Api(since: '1.0.0')]
final readonly class JobBatch
{
    public function __construct(
        public string $id,
        public string $name,
        public int $totalJobs,
        public int $pendingJobs,
        public int $failedJobs,
        public bool $cancelled,
        public bool $allowFailures,
        public int $createdAt,
        public ?int $finishedAt,
    ) {}

    /**
     * Whether all jobs in the batch have been processed.
     */
    public function isFinished(): bool
    {
        return $this->finishedAt !== null;
    }

    /**
     * Whether the batch completed without any failures.
     */
    public function isSuccessful(): bool
    {
        return $this->isFinished() && $this->failedJobs === 0;
    }

    /**
     * The number of jobs that have completed (successfully or failed).
     */
    public function processedJobs(): int
    {
        return $this->totalJobs - $this->pendingJobs;
    }
}
