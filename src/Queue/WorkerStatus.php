<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Represents the current operational state of a queue worker.
 */
#[Api]
enum WorkerStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
}
