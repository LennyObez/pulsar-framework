<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Monitor\ConnectionAuditor;
use Pulsar\Database\Monitor\MonitoredConnection;
use Pulsar\Database\Monitor\SlowQueryDetector;
use Pulsar\Database\Monitor\SqlLogger;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
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

        // Convenience binding: default connection available as ConnectionInterface.
        // When SQL monitoring is enabled, decorate the connection with logging,
        // slow-query detection, and connection auditing. The decorator is built
        // lazily and memoised so the single shared connection is wrapped exactly
        // once, independent of the wiring order of the logger/metrics services.
        if ($dbConfig->monitor->enabled) {
            $monitorConfig = $dbConfig->monitor;
            $monitored = null;
            $container->bind(
                ConnectionInterface::class,
                static function () use ($container, $connectionManager, $monitorConfig, &$monitored): ConnectionInterface {
                    if ($monitored instanceof ConnectionInterface) {
                        return $monitored;
                    }

                    $logger = $container->has(LoggerInterface::class)
                        ? $container->get(LoggerInterface::class)
                        : new NullLogger();
                    $metricRegistry = $container->has(MetricRegistry::class)
                        ? $container->get(MetricRegistry::class)
                        : null;

                    return $monitored = new MonitoredConnection(
                        $connectionManager->connection(),
                        new SqlLogger($logger, $monitorConfig),
                        new SlowQueryDetector($monitorConfig, $logger, $metricRegistry),
                        new ConnectionAuditor($logger),
                    );
                },
            );
        } else {
            $container->bind(ConnectionInterface::class, static fn(): ConnectionInterface => $connectionManager->connection());
        }

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
