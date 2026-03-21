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
     */
    public function check(): HealthCheckResult;
}
