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
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\Contract\OutboxPort;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\Internal\EventDispatcher;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Event\Internal\Outbox\DatabaseOutboxPort;
use Pulsar\Event\Internal\Outbox\DispatchingIntegrationEventBus;
use Pulsar\Event\Internal\Outbox\OutboxRelay;
use Pulsar\Event\Internal\StormGuard;
use Pulsar\Event\ListenerProviderInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Router;
use Pulsar\Saga\Port\IntegrationEventBusPort;

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

        // Use configured EventConfig or fall back to defaults so the event
        // dispatcher is always available for extensions that depend on it.
        if ($repository->has(EventConfig::class)) {
            /** @var EventConfig $eventConfig */
            $eventConfig = $repository->get(EventConfig::class);
        } else {
            $eventConfig = new EventConfig();
        }

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

        // Transactional outbox (ADR-0027), opt-in and DB-backed. Producers store
        // integration events via the OutboxPort inside their domain transaction;
        // the OutboxRelay publishes them after commit through the integration
        // event bus (in-process by default). Only wired when enabled and a
        // database connection is available.
        if ($eventConfig->outbox->enabled && $container->has(ConnectionInterface::class)) {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);

            $outbox = new DatabaseOutboxPort($connection);
            $outbox->installSchema();
            $container->instance(DatabaseOutboxPort::class, $outbox);
            $container->instance(OutboxPort::class, $outbox);

            $bus = new DispatchingIntegrationEventBus($dispatcher);
            $container->instance(IntegrationEventBusPort::class, $bus);

            $container->instance(OutboxRelay::class, new OutboxRelay(
                $outbox,
                $bus,
                $eventConfig->outbox->batchSize,
                $eventConfig->outbox->maxPublishAttempts,
            ));
        }
    }
}
