<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use Pulsar\Api\Api;

/**
 * Health status of a system component.
 */
#[Api]
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
