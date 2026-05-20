<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\ServiceDiscovery\ConfigCenterInterface;
use Pulsar\ServiceDiscovery\HealthCheckInterface;
use Pulsar\ServiceDiscovery\HttpHealthCheck;
use Pulsar\ServiceDiscovery\InMemoryServiceRegistry;
use Pulsar\ServiceDiscovery\ServiceDiscoveryInterface;
use Pulsar\ServiceDiscovery\ServiceInstance;
use Pulsar\ServiceDiscovery\ServiceRegistryInterface;
use Pulsar\ServiceDiscovery\StaticConfigCenter;
use Pulsar\ServiceDiscovery\StaticServiceDiscovery;

use function is_array;
use function is_file;
use function is_numeric;
use function is_string;

/**
 * Wires service discovery, registry, and config center into the container.
 */
#[Internal]
final readonly class ServiceDiscoveryWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $configPath = $configManager->configPath();

        if ($configPath === null) {
            return;
        }

        $configFile = $configPath . '/service_discovery.php';

        if (!is_file($configFile)) {
            return;
        }

        $config = require $configFile;

        if (!is_array($config)) {
            return;
        }

        $enabled = (bool) ($config['enabled'] ?? false);

        if (!$enabled) {
            return;
        }

        // Static discovery from config
        /** @var array<string, list<array{host: string, port: int, scheme?: string, healthy?: bool, metadata?: array<string, string>}>> $services */
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];

        $staticDiscovery = StaticServiceDiscovery::fromArray($services);
        $container->instance(StaticServiceDiscovery::class, $staticDiscovery);

        // In-memory registry with event dispatch
        $eventDispatcher = $container->has(EventDispatcherInterface::class)
            ? $container->get(EventDispatcherInterface::class)
            : null;

        /** @var ?EventDispatcherInterface $eventDispatcher */
        $registry = new InMemoryServiceRegistry($eventDispatcher);

        // Pre-register static services into the registry
        foreach ($services as $name => $instances) {
            foreach ($instances as $instanceData) {
                $staticInstance = new ServiceInstance(
                    name: $name,
                    host: $instanceData['host'],
                    port: $instanceData['port'],
                    scheme: $instanceData['scheme'] ?? 'https',
                    healthy: $instanceData['healthy'] ?? true,
                    metadata: $instanceData['metadata'] ?? [],
                );

                $ttl = is_numeric($config['default_ttl'] ?? null) ? (int) $config['default_ttl'] : null;
                $registry->register($staticInstance, $ttl);
            }
        }

        $container->instance(InMemoryServiceRegistry::class, $registry);
        $container->instance(ServiceDiscoveryInterface::class, $registry);
        $container->instance(ServiceRegistryInterface::class, $registry);

        // Health check
        /** @var mixed $rawHealthPath */
        $rawHealthPath = $config['health_path'] ?? null;
        /** @var mixed $rawHealthTimeout */
        $rawHealthTimeout = $config['health_timeout'] ?? null;
        $healthPath = is_string($rawHealthPath) ? $rawHealthPath : '/health';
        $healthTimeout = is_numeric($rawHealthTimeout) ? (float) $rawHealthTimeout : 5.0;
        $healthCheck = new HttpHealthCheck($healthPath, $healthTimeout);
        $container->instance(HealthCheckInterface::class, $healthCheck);
        $container->instance(HttpHealthCheck::class, $healthCheck);

        // Config center
        /** @var array<string, array<string, string>> $configCenterData */
        $configCenterData = is_array($config['config_center'] ?? null) ? $config['config_center'] : [];
        $configCenter = StaticConfigCenter::fromArray($configCenterData);
        $container->instance(ConfigCenterInterface::class, $configCenter);
        $container->instance(StaticConfigCenter::class, $configCenter);
    }
}
