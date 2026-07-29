<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\CacheManager;
use Pulsar\Cache\Application\Event\CacheEvent;
use Pulsar\Cache\Application\Event\CacheHitEvent;
use Pulsar\Cache\Application\Event\CacheMissEvent;
use Pulsar\Config\CallableConfigLoader;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Profiler\ProfilerConfig;
use Pulsar\Observability\Profiler\ProfilerMiddleware;
use Pulsar\Observability\Profiler\RequestProfiler;
use Pulsar\Routing\Router;

/**
 * Wires the per-request performance profiler (opt-in, for dev/staging).
 *
 * Binds a singleton {@see RequestProfiler}, pipes the {@see ProfilerMiddleware}
 * (outermost, so it times the whole stack), and subscribes to cache hit/miss
 * events. Database query timings are recorded automatically: the monitored
 * connection composes a profiler SQL logger when the profiler is bound.
 *
 * Owns config/profiler.php: its loader builds {@see ProfilerConfig} into the
 * ConfigRepository during config load (the single source of truth), so wire()
 * resolves it from the repository and unknown-key reporting is handled once,
 * centrally, by ConfigManager's post-load sweep.
 */
#[Internal]
final readonly class ProfilerWiring implements ServiceWiringInterface, ProvidesConfigLoaders
{
    public function configLoaders(): array
    {
        return [
            'profiler' => new CallableConfigLoader(
                ProfilerConfig::class,
                static fn(array $data): object => ProfilerConfig::fromArray($data),
            ),
        ];
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();
        $config = $repository->has(ProfilerConfig::class)
            ? $repository->get(ProfilerConfig::class)
            : new ProfilerConfig();
        $container->instance(ProfilerConfig::class, $config);

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
}
