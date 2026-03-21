<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use NoDiscard;
use Override;
use Pulsar\Api\Api;

use function array_filter;
use function array_find;
use function array_key_exists;
use function array_keys;
use function array_values;

/**
 * Configuration-driven service discovery implementation.
 *
 * Services are defined statically (typically from a config file) and stored
 * in memory. Registration and deregistration modify the in-memory state only,
 * making this implementation suitable for testing, local development, and
 * applications with known, fixed service topologies.
 * @api
 */
#[Api(since: '1.0.0')]
final class StaticServiceDiscovery implements ServiceDiscoveryInterface
{
    /** @var array<string, list<ServiceInstance>> */
    private array $registry = [];

    /**
     * Creates a discovery instance from a structured configuration array.
     *
     * @param array<string, list<array{host: string, port: int, scheme?: string, healthy?: bool, metadata?: array<string, string>}>> $services
     */
    #[NoDiscard]
    public static function fromArray(array $services): self
    {
        $discovery = new self();

        foreach ($services as $serviceName => $instances) {
            foreach ($instances as $entry) {
                $discovery->register(new ServiceInstance(
                    name: $serviceName,
                    host: $entry['host'],
                    port: $entry['port'],
                    scheme: $entry['scheme'] ?? 'https',
                    healthy: $entry['healthy'] ?? true,
                    metadata: $entry['metadata'] ?? [],
                ));
            }
        }

        return $discovery;
    }

    #[Override]
    public function instances(string $serviceName): array
    {
        return $this->registry[$serviceName] ?? [];
    }

    #[Override]
    public function instance(string $serviceName): ?ServiceInstance
    {
        if (!array_key_exists($serviceName, $this->registry)) {
            return null;
        }

        return array_find($this->registry[$serviceName], static fn(ServiceInstance $instance): bool => $instance->healthy);
    }

    #[Override]
    public function register(ServiceInstance $instance): void
    {
        if (!array_key_exists($instance->name, $this->registry)) {
            $this->registry[$instance->name] = [];
        }

        $this->registry[$instance->name][] = $instance;
    }

    #[Override]
    public function deregister(string $serviceName, string $host, int $port): void
    {
        if (!array_key_exists($serviceName, $this->registry)) {
            return;
        }

        $this->registry[$serviceName] = array_values(array_filter(
            $this->registry[$serviceName],
            static fn(ServiceInstance $instance): bool => $instance->host !== $host || $instance->port !== $port,
        ));

        if ($this->registry[$serviceName] === []) {
            unset($this->registry[$serviceName]);
        }
    }

    #[Override]
    public function services(): array
    {
        return array_keys($this->registry);
    }
}
