<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use Pulsar\Api\Api;

/**
 * Reason a worker recycle was triggered.
 */
#[Api(since: '1.0.0')]
enum RecycleReason: string
{
    case MaxRequests = 'max_requests';
    case MemoryThreshold = 'memory_threshold';
    case TimeLimit = 'time_limit';
    case Manual = 'manual';
}
