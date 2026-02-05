<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

#[Internal]
final readonly class DatabaseWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(DatabaseConfig::class)) {
            return;
        }

        /** @var DatabaseConfig $dbConfig */
        $dbConfig = $repository->get(DatabaseConfig::class);
        $container->instance(DatabaseConfig::class, $dbConfig);

        $connectionManager = ConnectionManager::fromConfig($dbConfig);
        $container->instance(ConnectionManager::class, $connectionManager);
        $container->instance(ConnectionManagerInterface::class, $connectionManager);

        // Convenience binding: default connection available as ConnectionInterface
        $container->bind(ConnectionInterface::class, static fn(): ConnectionInterface => $connectionManager->connection());
    }
}
