<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Pulsar\Api\Api;

/**
 * Cross-process store for the current database failover state.
 *
 * Database failover is detected out of band by a long-running watcher
 * ({@see \Pulsar\Console\Command\DbFailoverWatchCommand}); the resulting
 * active endpoint must be visible to every short-lived web/worker process so
 * they connect to the promoted primary without paying a per-request health
 * check. Implementations therefore persist the endpoint in shared, cross-process
 * storage (e.g. the configured cache backend).
 *
 * When no failover is active, {@see currentEndpoint()} returns null and callers
 * fall back to the configured primary.
 * @api
 */
#[Api(since: '1.0.0')]
interface FailoverStateStore
{
    /**
     * The endpoint a failover has switched to, or null when the configured
     * primary is still authoritative.
     */
    public function currentEndpoint(): ?string;

    /**
     * Record that failover switched the primary to the given endpoint.
     */
    public function recordFailover(string $endpoint): void;

    /**
     * Clear any recorded failover, reverting callers to the configured primary
     * (e.g. once the original primary is healthy again).
     */
    public function clear(): void;
}
