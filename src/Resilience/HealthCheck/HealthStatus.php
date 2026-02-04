<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

/**
 * Health status of a system component.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
