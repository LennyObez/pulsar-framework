<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Pulsar\Api\Api;

/**
 * Manages database failover detection and switching.
 *
 * Handles circuit-breaking and failover execution, but does NOT
 * handle promotion: that is the database cluster's responsibility.
 * @api
 */
#[Api(since: '1.0.0')]
interface FailoverManagerInterface
{
    /**
     * Check if the primary connection is reachable.
     */
    public function checkPrimary(): bool;

    /**
     * Execute a failover to the configured standby endpoint.
     *
     * Returns true if failover was successful, false otherwise.
     */
    public function executeFailover(): bool;

    /**
     * Check if the circuit breaker is open (failover is active).
     */
    public function isCircuitOpen(): bool;

    /**
     * Get the current primary endpoint.
     */
    public function getCurrentPrimary(): string;
}
