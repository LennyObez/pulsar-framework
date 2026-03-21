<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Domain;

use Pulsar\Api\Api;

/**
 * Overall system health status.
 * @api
 */
#[Api(since: '1.0.0')]
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
