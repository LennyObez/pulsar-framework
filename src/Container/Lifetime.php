<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Pulsar\Api\Api;

/**
 * Service lifetime strategy.
 *
 * Determines how long a resolved service instance lives.
 */
#[Api(since: '1.0.0')]
enum Lifetime: string
{
    /** Resolved once and cached for the application lifetime. */
    case Singleton = 'singleton';

    /** New instance created on each resolution. */
    case Transient = 'transient';

    /** Scoped to the current HTTP request; evicted between requests. */
    case RequestScope = 'request';

    /** Scoped to the current tenant; evicted on tenant context switch. */
    case TenantScope = 'tenant';
}
