<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;

/**
 * Direction of a migration operation.
 * @api
 */
#[Api(since: '1.0.0')]
enum MigrationDirection: string
{
    case Up = 'up';
    case Down = 'down';
}
