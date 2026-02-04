<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

/**
 * Strategy for isolating tenant data at the database level.
 */
enum TenantDatabaseStrategy: string
{
    case Prefix = 'prefix';
    case SeparateConnection = 'separate_connection';
    case Shared = 'shared';
}
