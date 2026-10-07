<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a job is dispatched to a queue.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class JobDispatched extends QueueEvent
{
    public function __construct(
        string $jobId,
        string $queue,
        string $jobClass,
        int $occurredAt,
        public int $delaySeconds,
    ) {
        parent::__construct($jobId, $queue, $jobClass, $occurredAt);
    }
}
