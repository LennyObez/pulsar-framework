<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\Cache\CachedQueryRunner;
use Pulsar\Database\Cache\QueryCache;
use Pulsar\Database\Cache\SensitivityMetadata;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Monitor\CompositeSqlLogger;
use Pulsar\Database\Monitor\ConnectionAuditor;
use Pulsar\Database\Monitor\MonitoredConnection;
use Pulsar\Database\Monitor\ProfilerSqlLogger;
use Pulsar\Database\Monitor\SlowQueryDetector;
use Pulsar\Database\Monitor\SqlLogger;
use Pulsar\Database\Routing\ReadWriteConnections;
use Pulsar\Database\Routing\ReadWriteRouter;
use Pulsar\Database\Routing\RoutingConnection;
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

        $readWriteConfig = $dbConfig->readWrite;

        // `read_hosts` and `write_host` are hosts, and the manager asks for connections
        // by name. Translate before anything is built, so the derived replica entries
        // are part of the DatabaseConfig every consumer sees — including whatever audits
        // the configured connections for TLS.
        if ($readWriteConfig->enabled) {
            $resolved = ReadWriteConnections::resolve($dbConfig, $readWriteConfig);
            $dbConfig = $resolved->database;
            $readWriteConfig = $resolved->routing;
        }

        $container->instance(DatabaseConfig::class, $dbConfig);

        $connectionManager = ConnectionManager::fromConfig($dbConfig);
        $container->instance(ConnectionManager::class, $connectionManager);

        // Read/write routing: when enabled, route SELECTs to read replicas and
        // keep writes (plus post-write reads, via stickiness) on the primary.
        // RoutingConnectionManager is a drop-in ConnectionManagerInterface that
        // wraps the plain manager and falls back to the primary when no read
        // hosts are configured, so enabling it without replicas is safe. Built
        // lazily so the optional audit logger is resolved after all wiring.
        if ($readWriteConfig->enabled) {
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
        $routesReads = $readWriteConfig->enabled && $readWriteConfig->readHosts !== [];
        $connection = null;
        $container->bind(
            ConnectionInterface::class,
            static function () use ($container, $monitorConfig, $routesReads, &$connection): ConnectionInterface {
                if ($connection instanceof ConnectionInterface) {
                    return $connection;
                }

                /** @var ConnectionManagerInterface $manager */
                $manager = $container->get(ConnectionManagerInterface::class);

                // Routing needs the statement, and only connectionForQuery() takes one.
                // Resolving `connection()` here instead would hand every caller a single
                // connection chosen before any SQL existed — which is how a fully
                // configured read/write deployment sent all of its reads to the primary.
                //
                // Multi-tenancy and failover both replace this binding after the database
                // is wired, and their decorators carry no connectionForQuery(). Behind one
                // of them routing cannot happen at all, so a deployment that configured
                // replicas is told, rather than left believing reads are being offloaded.
                if ($manager instanceof RoutingConnectionManager) {
                    $resolved = new RoutingConnection($manager);
                } elseif ($routesReads) {
                    throw DatabaseException::readWriteRoutingDecoratedAway($manager::class);
                } else {
                    $resolved = $manager->connection();
                }

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

        // Query cache: the `query_cache` section was parsed into a typed
        // QueryCacheConfig that no runtime object read, so `enabled`,
        // `default_ttl_seconds`, `sensitive_table_names` and `authorization_columns`
        // were four settings an operator could tune with no effect at all. This is the
        // consumer. Bound lazily: it needs the PSR-16 cache CacheWiring registers, and
        // the wiring order between the two is not fixed. Caching is opt-in per query,
        // so binding the runner changes nothing for an application that never asks for
        // it — see CachedQueryRunner for why a transparent read cache is not on offer.
        $queryCacheConfig = $dbConfig->queryCache;
        $container->bind(
            CachedQueryRunner::class,
            static function () use ($container, $queryCacheConfig): CachedQueryRunner {
                if (!$container->has(CacheInterface::class)) {
                    // Refused rather than answered with a runner that silently never
                    // caches: an application asking for this asked for a cache.
                    throw DatabaseException::queryCacheNeedsACacheStore();
                }

                /** @var CacheInterface $cache */
                $cache = $container->get(CacheInterface::class);

                /** @var ConnectionInterface $connection */
                $connection = $container->get(ConnectionInterface::class);

                return new CachedQueryRunner(
                    $connection,
                    new QueryCache($cache),
                    new SensitivityMetadata($queryCacheConfig),
                    $queryCacheConfig,
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
