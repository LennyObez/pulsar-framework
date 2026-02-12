<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Api;

/**
 * Runtime lifecycle status.
 */
#[Api(since: '1.0.0')]
enum RuntimeStatus: string
{
    case Stopped = 'stopped';
    case Starting = 'starting';
    case Running = 'running';
    case Draining = 'draining';
    case Stopping = 'stopping';
}
