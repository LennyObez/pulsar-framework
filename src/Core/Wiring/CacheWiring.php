<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\CacheManager;
use Pulsar\Cache\Application\CacheManagerInterface;
use Pulsar\Config\CacheConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\MasterKey;

#[Internal]
final readonly class CacheWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(CacheConfig::class)) {
            return;
        }

        /** @var CacheConfig $cacheConfig */
        $cacheConfig = $repository->get(CacheConfig::class);
        $container->instance(CacheConfig::class, $cacheConfig);

        if (!$cacheConfig->enabled) {
            return;
        }

        // Resolve optional dependencies
        $connection = $container->has(ConnectionInterface::class)
            ? $container->get(ConnectionInterface::class)
            : null;

        /** @var ConnectionInterface|null $connection */
        $masterKey = $container->has(MasterKey::class)
            ? $container->get(MasterKey::class)
            : null;

        /** @var MasterKey|null $masterKey */
        $metrics = $container->has(MetricRegistry::class)
            ? $container->get(MetricRegistry::class)
            : null;

        /** @var MetricRegistry|null $metrics */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $cacheManager = new CacheManager($cacheConfig, $connection, $masterKey, $metrics, $logger);
        $container->instance(CacheManager::class, $cacheManager);
        $container->instance(CacheManagerInterface::class, $cacheManager);

        // Bind default PSR-6 pool
        $container->instance(CacheItemPoolInterface::class, $cacheManager->pool());

        // Bind default PSR-16 simple cache
        $container->instance(CacheInterface::class, $cacheManager->simple());
    }
}
