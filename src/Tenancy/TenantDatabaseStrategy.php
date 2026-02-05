<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use Pulsar\Api\Api;

/**
 * Strategy for isolating tenant data at the database level.
 */
#[Api]
enum TenantDatabaseStrategy: string
{
    case Prefix = 'prefix';
    case SeparateConnection = 'separate_connection';
    case Shared = 'shared';
}
