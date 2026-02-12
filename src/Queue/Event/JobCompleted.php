<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a job completes successfully.
 */
#[Api(since: '1.0.0')]
final readonly class JobCompleted extends QueueEvent
{
    public function __construct(
        string $jobId,
        string $queue,
        string $jobClass,
        int $occurredAt,
        public int $attempt,
        public float $durationMs,
    ) {
        parent::__construct($jobId, $queue, $jobClass, $occurredAt);
    }
}
