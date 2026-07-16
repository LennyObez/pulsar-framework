<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\CacheManager;
use Pulsar\Cache\Application\CacheManagerInterface;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Exception\UnsupportedCapabilityException;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\CacheConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\MasterKey;

use function implode;

#[Internal]
final readonly class CacheWiring implements ServiceWiringInterface, DescribesWiring
{
    public function describeWiring(): WiringContract
    {
        return new WiringContract(
            component: 'cache',
            configClass: CacheConfig::class,
            configFile: 'cache.php',
            provides: [
                CacheConfig::class,
                CacheManager::class,
                CacheManagerInterface::class,
                CacheItemPoolInterface::class,
                CacheInterface::class,
                TaggedCacheInterface::class,
            ],
        );
    }

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

        // Surface silently-ignored configuration typos at boot: a misspelled key
        // (e.g. `tlt` for `ttl`) is parsed away and the pool quietly runs on a
        // default, which for a compliance-sensitive cache is a real hazard.
        if ($cacheConfig->unknownKeys !== [] && $logger !== null) {
            $logger->warning(
                'Unknown cache configuration keys were ignored: ' . implode(', ', $cacheConfig->unknownKeys),
                ['keys' => $cacheConfig->unknownKeys],
            );
        }

        $cacheManager = new CacheManager($cacheConfig, $connection, $masterKey, $metrics, $logger);
        $container->instance(CacheManager::class, $cacheManager);
        $container->instance(CacheManagerInterface::class, $cacheManager);

        // Bind default PSR-6 pool
        $container->instance(CacheItemPoolInterface::class, $cacheManager->pool());

        // Bind default PSR-16 simple cache
        $container->instance(CacheInterface::class, $cacheManager->simple());

        // Bind the tagged cache so tag-aware consumers wired later (anti-spam
        // single-use replay protection, duplicate detection, reputation
        // cooldowns) can resolve it. Without this binding those features detect
        // no TaggedCacheInterface and silently disable themselves. When the
        // default pool's driver cannot support tags we degrade with a loud log
        // rather than aborting boot.
        try {
            $container->instance(TaggedCacheInterface::class, $cacheManager->tagged());
        } catch (CacheException | UnsupportedCapabilityException $e) {
            $logger?->warning(
                'Tagged cache is unavailable for the default pool; tag-aware features '
                . '(anti-spam single-use, duplicate detection, reputation cooldowns) are disabled.',
                ['error' => $e->getMessage()],
            );
        }
    }
}
