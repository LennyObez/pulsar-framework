<?php

declare(strict_types=1);

namespace Pulsar\Resilience;

/**
 * State of a circuit breaker.
 */
enum CircuitBreakerState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
