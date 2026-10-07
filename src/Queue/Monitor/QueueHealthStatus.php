<?php

declare(strict_types=1);

namespace Pulsar\Queue\Monitor;

use Pulsar\Api\Api;

/**
 * Health status of a queue endpoint, disambiguated from Resilience\HealthCheck\HealthStatus.
 * @api
 */
#[Api(since: '1.0.0')]
enum QueueHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
