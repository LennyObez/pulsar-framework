<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Override;
use Pulsar\Api\Api;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\ServiceDiscovery\Event\ServiceDeregistered;
use Pulsar\ServiceDiscovery\Event\ServiceHealthChanged;
use Pulsar\ServiceDiscovery\Event\ServiceRegistered;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function time;

/**
 * In-memory service registry with TTL-based expiration and event dispatch.
 *
 * Implements both discovery (read) and registry (write) interfaces.
 * Suitable for single-process applications, testing, and as a local
 * cache in front of distributed backends (Consul, etcd).
 */
#[Api(since: '1.0.0')]
final class InMemoryServiceRegistry implements ServiceDiscoveryInterface, ServiceRegistryInterface
{
    /** @var array<string, array<string, ServiceTtlEntry>> service name → (host:port → entry) */
    private array $entries = [];

    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    #[Override]
    public function register(ServiceInstance $instance, ?int $ttlSeconds = null): void
    {
        $now = time();

        $entry = new ServiceTtlEntry(
            instance: $instance,
            ttlSeconds: $ttlSeconds,
            registeredAt: $now,
            lastHeartbeat: $now,
        );

        $this->entries[$instance->name][$entry->key()] = $entry;

        $this->eventDispatcher?->dispatch(new ServiceRegistered(
            serviceName: $instance->name,
            host: $instance->host,
            port: $instance->port,
            ttlSeconds: $ttlSeconds,
            occurredAt: $now,
        ));
    }

    #[Override]
    public function deregister(string $serviceName, string $host, int $port): void
    {
        $key = $host . ':' . $port;

        if (!isset($this->entries[$serviceName][$key])) {
            return;
        }

        unset($this->entries[$serviceName][$key]);

        if ($this->entries[$serviceName] === []) {
            unset($this->entries[$serviceName]);
        }

        $this->eventDispatcher?->dispatch(new ServiceDeregistered(
            serviceName: $serviceName,
            host: $host,
            port: $port,
            reason: 'manual',
            occurredAt: time(),
        ));
    }

    #[Override]
    public function deregisterAll(string $serviceName): void
    {
        $entries = $this->entries[$serviceName] ?? [];
        unset($this->entries[$serviceName]);

        foreach ($entries as $entry) {
            $this->eventDispatcher?->dispatch(new ServiceDeregistered(
                serviceName: $serviceName,
                host: $entry->instance->host,
                port: $entry->instance->port,
                reason: 'bulk_deregister',
                occurredAt: time(),
            ));
        }
    }

    #[Override]
    public function heartbeat(string $serviceName, string $host, int $port): bool
    {
        $key = $host . ':' . $port;

        if (!isset($this->entries[$serviceName][$key])) {
            return false;
        }

        $this->entries[$serviceName][$key]->refreshHeartbeat(time());

        return true;
    }

    #[Override]
    public function updateHealth(string $serviceName, string $host, int $port, ServiceHealthStatus $status): void
    {
        $key = $host . ':' . $port;

        if (!isset($this->entries[$serviceName][$key])) {
            return;
        }

        $entry = $this->entries[$serviceName][$key];
        $previous = $entry->healthStatus;

        if ($previous === $status) {
            return;
        }

        $entry->healthStatus = $status;

        $this->eventDispatcher?->dispatch(new ServiceHealthChanged(
            serviceName: $serviceName,
            host: $host,
            port: $port,
            previousStatus: $previous,
            newStatus: $status,
            occurredAt: time(),
        ));
    }

    #[Override]
    public function evictExpired(): int
    {
        $now = time();
        $evicted = 0;

        foreach ($this->entries as $serviceName => $instances) {
            foreach ($instances as $key => $entry) {
                if ($entry->isExpired($now)) {
                    unset($this->entries[$serviceName][$key]);
                    $evicted++;

                    $this->eventDispatcher?->dispatch(new ServiceDeregistered(
                        serviceName: $serviceName,
                        host: $entry->instance->host,
                        port: $entry->instance->port,
                        reason: 'ttl_expired',
                        occurredAt: $now,
                    ));
                }
            }

            if (isset($this->entries[$serviceName]) && $this->entries[$serviceName] === []) {
                unset($this->entries[$serviceName]);
            }
        }

        return $evicted;
    }

    #[Override]
    public function instances(string $serviceName): array
    {
        $this->evictExpired();

        if (!isset($this->entries[$serviceName])) {
            return [];
        }

        return array_values(array_map(
            static fn(ServiceTtlEntry $e): ServiceInstance => $e->instance,
            array_filter(
                $this->entries[$serviceName],
                static fn(ServiceTtlEntry $e): bool => !$e->isExpired(time()),
            ),
        ));
    }

    #[Override]
    public function instance(string $serviceName): ?ServiceInstance
    {
        $this->evictExpired();

        if (!isset($this->entries[$serviceName])) {
            return null;
        }

        foreach ($this->entries[$serviceName] as $entry) {
            if ($entry->healthStatus === ServiceHealthStatus::Healthy) {
                return $entry->instance;
            }
        }

        return null;
    }

    #[Override]
    public function services(): array
    {
        $this->evictExpired();

        return array_keys($this->entries);
    }
}
