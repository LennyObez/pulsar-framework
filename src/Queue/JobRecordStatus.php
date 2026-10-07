<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Lifecycle status of a queued job record.
 * @api
 */
#[Api(since: '1.0.0')]
enum JobRecordStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case DeadLettered = 'dead_lettered';
}
