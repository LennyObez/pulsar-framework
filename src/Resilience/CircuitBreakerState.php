<?php

declare(strict_types=1);

namespace Pulsar\Resilience;

use Pulsar\Api\Api;

/**
 * State of a circuit breaker.
 * @api
 */
#[Api(since: '1.0.0')]
enum CircuitBreakerState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
