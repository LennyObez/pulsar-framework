<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Pulsar\Api\Api;

/**
 * Health check contract for service instances.
 *
 * Implementations perform active health probing (HTTP, TCP, gRPC)
 * and return the current health status.
 * @api
 */
#[Api(since: '1.0.0')]
interface HealthCheckInterface
{
    /**
     * Perform a health check against a service instance.
     */
    public function check(ServiceInstance $instance): HealthCheckResult;
}
