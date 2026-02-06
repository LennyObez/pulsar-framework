<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Immutable snapshot of a queued job's state.
 */
#[Api]
readonly class JobRecord
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $jobClass,
        public string $payload,
        public int $attempts,
        public JobRecordStatus $status,
        public int $createdAt,
        public int $availableAt,
    ) {}
}
