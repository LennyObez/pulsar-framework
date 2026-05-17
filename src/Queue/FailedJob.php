<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Immutable record of a job that has been moved to the dead-letter queue.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FailedJob
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $jobClass,
        public string $payload,
        public string $exception,
        public int $failedAt,
        public int $attempts,
    ) {}
}
