<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Lifecycle status of a queued job record.
 */
#[Api]
enum JobRecordStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case DeadLettered = 'dead_lettered';
}
