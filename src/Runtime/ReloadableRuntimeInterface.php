<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Api;
use Pulsar\Runtime\Worker\HealthStatus;
use Pulsar\Runtime\Worker\WorkerInfo;

/**
 * Contract for runtimes that support graceful reload and health reporting.
 *
 * Reload lifecycle:
 * 1. Signal received (SIGUSR1, HTTP, or CLI)
 * 2. Stop accepting new requests (draining)
 * 3. Drain in-flight requests (configurable timeout)
 * 4. Recycle — worker exits, supervisor spawns fresh process
 */
#[Api(since: '1.0.0')]
interface ReloadableRuntimeInterface extends RuntimeInterface
{
    /**
     * Trigger graceful reload: stop accepting → drain → recycle.
     */
    public function reload(): void;

    /**
     * Get the current health status for monitoring.
     */
    public function healthStatus(): HealthStatus;

    /**
     * Get detailed worker information.
     */
    public function workerInfo(): WorkerInfo;
}
