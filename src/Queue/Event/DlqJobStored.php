<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a failed job is stored in the dead-letter queue.
 */
#[Api(since: '1.0.0')]
final readonly class DlqJobStored
{
    public function __construct(
        public string $jobId,
        public string $queue,
        public string $jobClass,
        public string $reason,
        public string $correlationId,
        public int $timestamp,
    ) {}
}
