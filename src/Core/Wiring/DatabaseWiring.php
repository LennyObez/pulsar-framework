<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Monitor\CompositeSqlLogger;
use Pulsar\Database\Monitor\ConnectionAuditor;
use Pulsar\Database\Monitor\MonitoredConnection;
use Pulsar\Database\Monitor\ProfilerSqlLogger;
use Pulsar\Database\Monitor\SlowQueryDetector;
use Pulsar\Database\Monitor\SqlLogger;
use Pulsar\Database\Routing\ReadWriteRouter;
use Pulsar\Database\Routing\RoutingConnectionManager;
use Pulsar\Database\Routing\StickinessContext;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Profiler\RequestProfiler;
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

        // Read/write routing: when enabled, route SELECTs to read replicas and
        // keep writes (plus post-write reads, via stickiness) on the primary.
        // RoutingConnectionManager is a drop-in ConnectionManagerInterface that
        // wraps the plain manager and falls back to the primary when no read
        // hosts are configured, so enabling it without replicas is safe. Built
        // lazily so the optional audit logger is resolved after all wiring.
        if ($dbConfig->readWrite->enabled) {
            $readWriteConfig = $dbConfig->readWrite;
            $routingManager = null;
            $container->bind(
                ConnectionManagerInterface::class,
                static function () use ($container, $connectionManager, $readWriteConfig, &$routingManager): ConnectionManagerInterface {
                    if ($routingManager instanceof ConnectionManagerInterface) {
                        return $routingManager;
                    }

                    $auditLogger = $container->has(AuditLoggerInterface::class)
                        ? $container->get(AuditLoggerInterface::class)
                        : null;

                    return $routingManager = new RoutingConnectionManager(
                        $connectionManager,
                        new ReadWriteRouter(),
                        new StickinessContext(),
                        $readWriteConfig,
                        $auditLogger,
                    );
                },
            );
        } else {
            $container->instance(ConnectionManagerInterface::class, $connectionManager);
        }

        // Convenience binding: default connection available as ConnectionInterface,
        // resolved through the (possibly routing-aware) connection manager. When SQL
        // monitoring is enabled, the connection is additionally decorated with
        // logging, slow-query detection, and connection auditing. Built lazily and
        // memoised so the shared connection is resolved/wrapped exactly once,
        // independent of the wiring order of the manager/logger/metrics services.
        $monitorConfig = $dbConfig->monitor;
        $connection = null;
        $container->bind(
            ConnectionInterface::class,
            static function () use ($container, $monitorConfig, &$connection): ConnectionInterface {
                if ($connection instanceof ConnectionInterface) {
                    return $connection;
                }

                /** @var ConnectionManagerInterface $manager */
                $manager = $container->get(ConnectionManagerInterface::class);
                $resolved = $manager->connection();

                $profiler = null;
                if ($container->has(RequestProfiler::class)) {
                    /** @var RequestProfiler $profiler */
                    $profiler = $container->get(RequestProfiler::class);
                }

                // Production fast path: leave the connection undecorated when neither
                // SQL monitoring nor the request profiler needs query instrumentation.
                if (!$monitorConfig->enabled && $profiler === null) {
                    return $connection = $resolved;
                }

                $logger = $container->has(LoggerInterface::class)
                    ? $container->get(LoggerInterface::class)
                    : new NullLogger();
                $metricRegistry = $container->has(MetricRegistry::class)
                    ? $container->get(MetricRegistry::class)
                    : null;

                // Full monitor logger when monitoring is on (tee-ing into the profiler
                // when both are active); profiler-only logger when just the profiler is
                // enabled, so turning the profiler on is sufficient to get DB timings.
                // Slow-query/audit logging mirror the monitor: silent when it is off,
                // so enabling only the profiler never emits audit lines the operator
                // turned off.
                if ($monitorConfig->enabled) {
                    $sqlLogger = new SqlLogger($logger, $monitorConfig);
                    if ($profiler !== null) {
                        $sqlLogger = new CompositeSqlLogger([$sqlLogger, new ProfilerSqlLogger($profiler)]);
                    }
                    $monitorLogger = $logger;
                } else {
                    $sqlLogger = new ProfilerSqlLogger($profiler);
                    $monitorLogger = new NullLogger();
                }

                return $connection = new MonitoredConnection(
                    $resolved,
                    $sqlLogger,
                    new SlowQueryDetector($monitorConfig, $monitorLogger, $metricRegistry),
                    new ConnectionAuditor($monitorLogger),
                );
            },
        );

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
