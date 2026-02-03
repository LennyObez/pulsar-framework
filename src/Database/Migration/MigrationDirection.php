<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

/**
 * Direction of a migration operation.
 */
enum MigrationDirection: string
{
    case Up = 'up';
    case Down = 'down';
}
