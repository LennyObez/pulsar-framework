<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use Pulsar\Api\Api;

/**
 * Action the supervisor takes when a worker recycle is triggered.
 */
#[Api]
enum RecycleAction: string
{
    case GracefulRestart = 'graceful_restart';
    case ForceRestart = 'force_restart';
    case Skip = 'skip';
}
