<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Api;

/**
 * Runtime lifecycle status.
 */
#[Api]
enum RuntimeStatus: string
{
    case Stopped = 'stopped';
    case Starting = 'starting';
    case Running = 'running';
    case Stopping = 'stopping';
}
