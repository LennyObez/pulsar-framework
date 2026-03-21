<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;

/**
 * Executes all registered health checks and returns a snapshot.
 * @api
 */
#[Api(since: '1.0.0')]
interface HealthCheckRunnerInterface
{
    /**
     * Run all registered health checks and return a snapshot.
     */
    public function run(): HealthSnapshot;
}
