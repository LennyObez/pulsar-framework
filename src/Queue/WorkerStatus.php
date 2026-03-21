<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Represents the current operational state of a queue worker.
 * @api
 */
#[Api(since: '1.0.0')]
enum WorkerStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
}
