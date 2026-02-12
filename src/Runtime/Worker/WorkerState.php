<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Worker;

use Pulsar\Api\Api;

/**
 * Worker lifecycle state.
 */
#[Api(since: '1.0.0')]
enum WorkerState: string
{
    case Booting = 'booting';
    case Ready = 'ready';
    case Handling = 'handling';
    case Draining = 'draining';
    case Recycling = 'recycling';
    case Stopped = 'stopped';
}
