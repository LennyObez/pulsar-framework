<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Pulsar\Api\Internal;

/**
 * Route handler resolution strategy.
 */
#[Internal]
enum RouteHandlerType: string
{
    /** Single container-resolvable string with __invoke(). */
    case Invokable = 'invokable';

    /** [resolvable, method] pair resolved via container. */
    case Method = 'method';
}
