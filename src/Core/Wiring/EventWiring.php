<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\EventConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\Internal\EventDispatcher;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Event\Internal\StormGuard;
use Pulsar\Event\ListenerProviderInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Router;

#[Internal]
final readonly class EventWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(EventConfig::class)) {
            return;
        }

        /** @var EventConfig $eventConfig */
        $eventConfig = $repository->get(EventConfig::class);
        $container->instance(EventConfig::class, $eventConfig);

        if (!$eventConfig->enabled) {
            return;
        }

        // Storm guard
        $stormGuard = new StormGuard($eventConfig->stormProtection);
        $container->instance(StormGuard::class, $stormGuard);

        // Listener provider
        $listenerProvider = new ListenerProvider();
        $container->instance(ListenerProvider::class, $listenerProvider);
        $container->instance(ListenerProviderInterface::class, $listenerProvider);
        $container->instance(PsrListenerProviderInterface::class, $listenerProvider);

        // Metrics (optional)
        $metrics = $container->has(MetricRegistry::class)
            ? $container->get(MetricRegistry::class)
            : null;

        /** @var MetricRegistry|null $metrics */

        // Logger (optional)
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */

        // Event dispatcher
        $dispatcher = new EventDispatcher($listenerProvider, $listenerProvider, $stormGuard, $metrics, $logger);
        $container->instance(EventDispatcher::class, $dispatcher);
        $container->instance(EventDispatcherInterface::class, $dispatcher);
        $container->instance(PsrEventDispatcherInterface::class, $dispatcher);
    }
}
