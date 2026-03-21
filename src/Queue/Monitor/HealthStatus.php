<?php

declare(strict_types=1);

namespace Pulsar\Queue\Monitor;

use Pulsar\Api\Api;

/**
 * Health status of a queue endpoint.
 * @api
 */
#[Api(since: '1.0.0')]
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
