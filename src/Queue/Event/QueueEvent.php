<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Base class for all queue system events.
 * @api
 */
#[Api(since: '1.0.0')]
abstract readonly class QueueEvent
{
    public function __construct(
        public string $jobId,
        public string $queue,
        public string $jobClass,
        public int $occurredAt,
    ) {}
}
