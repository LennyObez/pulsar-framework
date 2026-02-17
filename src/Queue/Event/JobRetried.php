<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a failed job is scheduled for retry.
 */
#[Api(since: '1.0.0')]
final readonly class JobRetried extends QueueEvent
{
    public function __construct(
        string $jobId,
        string $queue,
        string $jobClass,
        int $occurredAt,
        public int $attempt,
        public int $nextAttempt,
        public int $delayMs,
    ) {
        parent::__construct($jobId, $queue, $jobClass, $occurredAt);
    }
}
