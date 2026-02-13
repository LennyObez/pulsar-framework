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

        // Seeder runner: discovers and executes database seeders.
        // Uses lazy binding so ConnectionInterface is resolved at use time, not at wiring time.
        $seederFactory = static function () use ($connectionManager): \Pulsar\Database\Seeder\SeederRunner {
            $basePath = getcwd() ?: '.';

            return new \Pulsar\Database\Seeder\SeederRunner(
                $connectionManager->connection(),
                $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders',
            );
        };

        $container->bind(\Pulsar\Database\Seeder\SeederRunner::class, $seederFactory);
        $container->bind(\Pulsar\Database\Seeder\SeederRunnerInterface::class, $seederFactory);
    }
}
