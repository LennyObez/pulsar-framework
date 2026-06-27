<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Saga\SagaStateStorageInterface;
use Pulsar\Saga\Storage\InMemorySagaStateStorage;
use Pulsar\Workflow\Internal\Storage\DatabaseSagaStateStorage;

/**
 * Registers a default saga-state storage so the saga engine is runnable out of
 * the box (the SagaStateStorageInterface port previously had no concrete
 * implementation, leaving the engine unusable).
 *
 * A durable database-backed store is used when a database connection is
 * available — required to resume sagas across process restarts; otherwise a
 * process-local in-memory store (sufficient for tests / single-process use). An
 * application or extension that binds its own SagaStateStorageInterface takes
 * precedence.
 */
#[Internal]
final readonly class SagaWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        if ($container->has(SagaStateStorageInterface::class)) {
            return;
        }

        if ($container->has(ConnectionManagerInterface::class)) {
            /** @var ConnectionManagerInterface $connectionManager */
            $connectionManager = $container->get(ConnectionManagerInterface::class);
            $storage = new DatabaseSagaStateStorage($connectionManager->connection());
        } else {
            $storage = new InMemorySagaStateStorage();
        }

        $container->instance(SagaStateStorageInterface::class, $storage);
    }
}
