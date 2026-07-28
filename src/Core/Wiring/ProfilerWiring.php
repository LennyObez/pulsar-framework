<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\CacheManager;
use Pulsar\Cache\Application\Event\CacheEvent;
use Pulsar\Cache\Application\Event\CacheHitEvent;
use Pulsar\Cache\Application\Event\CacheMissEvent;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Internal\ReportsConfigKeys;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Profiler\ProfilerConfig;
use Pulsar\Observability\Profiler\ProfilerMiddleware;
use Pulsar\Observability\Profiler\RequestProfiler;
use Pulsar\Routing\Router;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Wires the per-request performance profiler (opt-in, for dev/staging).
 *
 * Binds a singleton {@see RequestProfiler}, pipes the {@see ProfilerMiddleware}
 * (outermost, so it times the whole stack), and subscribes to cache hit/miss
 * events. Database query timings are recorded automatically: the monitored
 * connection composes a profiler SQL logger when the profiler is bound.
 */
#[Internal]
final readonly class ProfilerWiring implements ServiceWiringInterface
{
    use ReportsConfigKeys;

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $config = $this->loadConfig($configManager);
        $container->instance(ProfilerConfig::class, $config);
        $this->reportUnknownConfigKeys($container, 'profiler', $config);

        if (!$config->enabled) {
            return;
        }

        $profiler = new RequestProfiler(
            enabled: true,
            maxEntries: $config->maxEntries,
            maxProfiles: $config->maxProfiles,
        );
        $container->instance(RequestProfiler::class, $profiler);

        // Cache instrumentation: record hit/miss into the profiler.
        if ($container->has(CacheManager::class)) {
            /** @var CacheManager $cacheManager */
            $cacheManager = $container->get(CacheManager::class);
            $cacheManager->addEventListener(static function (CacheEvent $event) use ($profiler): void {
                if ($event instanceof CacheHitEvent) {
                    $profiler->recordCacheHit($event->hashedKey);
                } elseif ($event instanceof CacheMissEvent) {
                    $profiler->recordCacheMiss($event->hashedKey);
                }
            });
        }

        $profilerMiddleware = new ProfilerMiddleware($profiler);
        $container->instance(ProfilerMiddleware::class, $profilerMiddleware);

        $middleware->pipe($profilerMiddleware);
    }

    private function loadConfig(ConfigManager $configManager): ProfilerConfig
    {
        $configPath = $configManager->configPath();

        if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'profiler.php')) {
            /**
             * @psalm-suppress UnresolvableInclude
             * @var mixed $data
             */
            $data = require $configPath . DIRECTORY_SEPARATOR . 'profiler.php';

            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                return ProfilerConfig::fromArray($data);
            }
        }

        return new ProfilerConfig();
    }
}
