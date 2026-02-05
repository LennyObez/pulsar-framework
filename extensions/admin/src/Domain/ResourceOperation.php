<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Admin resource operations for permission checks.
 */
#[Api(since: '1.0.0')]
enum ResourceOperation: string
{
    case List = 'list';
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Export = 'export';
    case BulkAction = 'bulk_action';
}
