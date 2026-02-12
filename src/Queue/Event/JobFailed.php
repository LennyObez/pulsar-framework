<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a job fails execution.
 */
#[Api(since: '1.0.0')]
final readonly class JobFailed extends QueueEvent
{
    public function __construct(
        string $jobId,
        string $queue,
        string $jobClass,
        int $occurredAt,
        public int $attempt,
        public string $exceptionClass,
        public string $exceptionMessage,
    ) {
        parent::__construct($jobId, $queue, $jobClass, $occurredAt);
    }
}
