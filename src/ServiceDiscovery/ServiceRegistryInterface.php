<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Pulsar\Api\Api;

/**
 * Registry for service instances with lifecycle management.
 *
 * Implementations handle registration, deregistration, TTL expiration,
 * and health status tracking. The registry is the write-side companion
 * to {@see ServiceDiscoveryInterface} (read-side).
 */
#[Api(since: '1.0.0')]
interface ServiceRegistryInterface
{
    /**
     * Register a service instance.
     *
     * If TTL is provided, the registration expires after the given number
     * of seconds unless refreshed via {@see heartbeat()}.
     */
    public function register(ServiceInstance $instance, ?int $ttlSeconds = null): void;

    /**
     * Remove a specific service instance from the registry.
     */
    public function deregister(string $serviceName, string $host, int $port): void;

    /**
     * Remove all instances for a service.
     */
    public function deregisterAll(string $serviceName): void;

    /**
     * Refresh the TTL for a registered service instance.
     *
     * Returns true if the instance was found and refreshed, false if not found.
     */
    public function heartbeat(string $serviceName, string $host, int $port): bool;

    /**
     * Update the health status of a service instance.
     */
    public function updateHealth(string $serviceName, string $host, int $port, ServiceHealthStatus $status): void;

    /**
     * Evict all expired service registrations.
     *
     * @return int Number of instances evicted
     */
    public function evictExpired(): int;
}
