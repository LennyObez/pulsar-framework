<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Console\Command\DbFailoverWatchCommand;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\OptionalBinding;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Failover\CacheBackedFailoverStateStore;
use Pulsar\Database\Failover\DnsFailoverStrategy;
use Pulsar\Database\Failover\FailoverConnectionManager;
use Pulsar\Database\Failover\FailoverManager;
use Pulsar\Database\Failover\FailoverManagerInterface;
use Pulsar\Database\Failover\FailoverStateStore;
use Pulsar\Database\Failover\FailoverStrategyInterface;
use Pulsar\Database\Health\ConnectionHealthChecker;
use Pulsar\Database\PdoConnection;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Routing\Router;

use function sprintf;

/**
 * Activates database failover when {@see DatabaseConfig::$failover} is enabled.
 *
 * Wires three collaborators:
 *  - a shared {@see FailoverStateStore} (cache-backed) holding the promoted endpoint;
 *  - a {@see FailoverConnectionManager} decorator that, for the primary
 *    connection, honours an active failover with no per-request health check;
 *  - the {@see DbFailoverWatchCommand} daemon that detects failure out of band
 *    and publishes the new endpoint to the store.
 *
 * Runs late (after the connection manager, cache, and audit logger are wired):
 * it resolves the existing ConnectionManagerInterface eagerly to decorate it.
 * Construction opens no database connection — the daemon's manager is built
 * lazily only when the watcher runs.
 *
 * Requires the cache (cross-process state) and a buildable failover strategy.
 * The 'dns' strategy is built from the connection host; 'config-reload' and
 * 'callback' require an application-bound {@see FailoverStrategyInterface}.
 */
#[Internal]
final readonly class FailoverWiring implements ServiceWiringInterface, DescribesWiring
{
    public function describeWiring(): WiringContract
    {
        return new WiringContract(
            component: 'database-failover',
            configClass: DatabaseConfig::class,
            configFile: 'database.php',
            provides: [
                FailoverStateStore::class,
                DbFailoverWatchCommand::class,
            ],
            optional: [
                new OptionalBinding(
                    binding: CacheInterface::class,
                    feature: 'database failover (cross-process state for the promoted endpoint)',
                    fix: 'Enable the cache (CacheWiring binds CacheInterface when cache is enabled).',
                    security: false,
                ),
            ],
        );
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        if (!$container->has(DatabaseConfig::class) || !$container->has(ConnectionManagerInterface::class)) {
            return;
        }

        /** @var DatabaseConfig $dbConfig */
        $dbConfig = $container->get(DatabaseConfig::class);

        if (!$dbConfig->failover->enabled) {
            return;
        }

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();
        /** @var LoggerInterface $logger */

        $defaultName = $dbConfig->defaultConnection;
        $connConfig = $dbConfig->connections[$defaultName] ?? null;

        if (!$connConfig instanceof ConnectionConfig) {
            $logger->warning('Database failover is enabled but the default connection is not configured; failover is inert.');

            return;
        }

        $strategy = $this->resolveStrategy($dbConfig, $connConfig, $container);

        if ($strategy === null) {
            $logger->warning(sprintf(
                'Database failover strategy "%s" requires an application-bound %s; failover is inert.',
                $dbConfig->failover->strategy,
                FailoverStrategyInterface::class,
            ));

            return;
        }

        if (!$container->has(CacheInterface::class)) {
            $logger->warning(
                'Database failover is enabled but no cache is bound (CacheInterface); the promoted endpoint '
                . 'cannot be shared across processes, so failover is inert. Enable the cache.',
            );

            return;
        }

        /** @var CacheInterface $cache */
        $cache = $container->get(CacheInterface::class);
        $store = new CacheBackedFailoverStateStore($cache);
        $container->instance(FailoverStateStore::class, $store);

        // Decorate the effective connection manager (plain or read/write-routing).
        // Resolving it here is safe: this wiring runs late and the manager opens
        // no connection at construction.
        /** @var ConnectionManagerInterface $inner */
        $inner = $container->get(ConnectionManagerInterface::class);
        $container->instance(
            ConnectionManagerInterface::class,
            new FailoverConnectionManager($inner, $store, $dbConfig),
        );

        $metrics = $container->has(MetricRegistry::class)
            ? $container->get(MetricRegistry::class)
            : new MetricRegistry();
        /** @var MetricRegistry $metrics */

        $failoverConfig = $dbConfig->failover;
        $primaryEndpoint = $connConfig->host;

        // The daemon's manager is built lazily: constructing the command (which
        // the CLI does on every invocation) must not open a database connection.
        $managerFactory = function () use (
            $container,
            $defaultName,
            $connConfig,
            $strategy,
            $failoverConfig,
            $metrics,
            $primaryEndpoint,
            $logger,
        ): FailoverManagerInterface {
            // Health-check the plain primary (never the failover-decorated manager).
            /** @var ConnectionManager $plain */
            $plain = $container->get(ConnectionManager::class);

            return new FailoverManager(
                primaryConnection: $plain->connection($defaultName),
                healthChecker: new ConnectionHealthChecker(),
                strategy: $strategy,
                circuitBreaker: new CircuitBreaker(
                    name: 'db-primary',
                    failureThreshold: $failoverConfig->failureThreshold,
                    successThreshold: 1,
                    openTimeoutSeconds: 30,
                    logger: $logger,
                ),
                config: $failoverConfig,
                metrics: $metrics,
                primaryEndpoint: $primaryEndpoint,
                // DNS re-resolution picks up the promoted endpoint on reconnect;
                // for the other strategies the store still holds the authoritative
                // endpoint that web/worker processes connect to.
                connectionFactory: static fn(): PdoConnection => PdoConnection::fromConfig($connConfig),
            );
        };

        $container->instance(
            DbFailoverWatchCommand::class,
            new DbFailoverWatchCommand($managerFactory, $store, $failoverConfig->retryIntervalSeconds, $logger),
        );
    }

    /**
     * Build the failover strategy. 'dns' is derived from the connection host;
     * 'config-reload' and 'callback' need an application-bound strategy.
     */
    private function resolveStrategy(
        DatabaseConfig $dbConfig,
        ConnectionConfig $connConfig,
        ContainerInterface $container,
    ): ?FailoverStrategyInterface {
        if ($dbConfig->failover->strategy === 'dns') {
            return new DnsFailoverStrategy($connConfig->host);
        }

        if ($container->has(FailoverStrategyInterface::class)) {
            /** @var FailoverStrategyInterface $strategy */
            $strategy = $container->get(FailoverStrategyInterface::class);

            return $strategy;
        }

        return null;
    }
}
