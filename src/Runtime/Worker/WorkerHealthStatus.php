<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Worker;

use Pulsar\Api\Api;

/**
 * Worker health status for readiness/liveness probes, disambiguated from Resilience\HealthCheck\HealthStatus.
 */
#[Api(since: '1.0.0')]
enum WorkerHealthStatus: string
{
    case Healthy = 'healthy';
    case Draining = 'draining';
    case ShuttingDown = 'shutting_down';
}
