<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Available queue driver types.
 */
#[Api(since: '1.0.0')]
enum QueueDriverType: string
{
    case Sync = 'sync';
    case Database = 'database';
    case Memory = 'memory';
}
