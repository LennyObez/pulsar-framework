<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Pulsar\Api\Api;

/**
 * Contract for service discovery backends.
 *
 * Implementations may range from static configuration files to dynamic
 * registries (Consul, etcd, Kubernetes). The GA release ships with
 * {@see StaticServiceDiscovery}; dynamic backends are planned for post-GA.
 * @api
 */
#[Api(since: '1.0.0')]
interface ServiceDiscoveryInterface
{
    /**
     * Returns all registered instances for the given service name.
     *
     * @return list<ServiceInstance>
     */
    public function instances(string $serviceName): array;

    /**
     * Returns the first healthy instance for the given service name, or null if none are available.
     */
    public function instance(string $serviceName): ?ServiceInstance;

    /**
     * Registers a service instance in the discovery registry.
     */
    public function register(ServiceInstance $instance): void;

    /**
     * Removes a specific service instance identified by service name, host, and port.
     */
    public function deregister(string $serviceName, string $host, int $port): void;

    /**
     * Returns all known service names.
     *
     * @return list<string>
     */
    public function services(): array;
}
