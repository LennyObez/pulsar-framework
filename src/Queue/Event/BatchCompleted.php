<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when all jobs in a batch have completed (successfully or with allowed failures).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BatchCompleted
{
    public function __construct(
        public string $batchId,
        public string $batchName,
        public int $totalJobs,
        public int $failedJobs,
        public int $occurredAt,
    ) {}
}
