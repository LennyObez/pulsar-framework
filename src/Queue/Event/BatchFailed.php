<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a batch fails due to a job failure (when failures are not allowed).
 */
#[Api(since: '1.0.0')]
final readonly class BatchFailed
{
    public function __construct(
        public string $batchId,
        public string $batchName,
        public int $totalJobs,
        public int $failedJobs,
        public string $failedJobId,
        public int $occurredAt,
    ) {}
}
