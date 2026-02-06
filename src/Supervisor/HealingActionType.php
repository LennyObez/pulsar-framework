<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use Pulsar\Api\Api;

/**
 * Categories of self-healing actions the supervisor can perform.
 */
#[Api]
enum HealingActionType: string
{
    case WorkerRecycle = 'worker_recycle';
    case StuckJobRecovery = 'stuck_job_recovery';
    case CachePurge = 'cache_purge';
    case ConnectionReset = 'connection_reset';
}
