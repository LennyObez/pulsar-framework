<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use Pulsar\Api\Api;

/**
 * Interface for system health checks.
 * @api
 */
#[Api(since: '1.0.0')]
interface HealthCheckInterface
{
    /**
     * Get the health check name.
     */
    public function getName(): string;

    /**
     * Run the health check and return the result.
     *
     * Implementations must not throw: catch all exceptions internally and return
     * an unhealthy {@see HealthCheckResult} instead. The runner guards against a
     * throwing implementation, but a thrown exception loses the per-check detail
     * the implementation could otherwise have reported.
     */
    public function check(): HealthCheckResult;
}
