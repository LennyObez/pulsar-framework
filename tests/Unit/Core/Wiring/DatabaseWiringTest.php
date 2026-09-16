<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\DatabaseWiring;
use Pulsar\Database\Cache\CachedQueryRunner;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Monitor\MonitoredConnection;
use Pulsar\Database\Routing\RoutingConnection;
use Pulsar\Database\Routing\RoutingConnectionManager;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Profiler\RequestProfiler;
use Pulsar\Routing\Router;
use Pulsar\Tests\Unit\Database\Cache\Support\ArraySimpleCache;

#[CoversClass(DatabaseWiring::class)]
final class DatabaseWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersConnectionManagerWhenConfigPresent(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        $wiring = new DatabaseWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(DatabaseConfig::class));
        self::assertTrue($container->has(ConnectionManager::class));
        self::assertTrue($container->has(ConnectionManagerInterface::class));
    }

    #[Test]
    public function wireSkipsWhenNoDatabaseConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: false);
        $configManager->load();

        $wiring = new DatabaseWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(DatabaseConfig::class));
        self::assertFalse($container->has(ConnectionManager::class));
    }

    #[Test]
    public function wireDecoratesConnectionWithMonitoringWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true, monitorEnabled: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertInstanceOf(MonitoredConnection::class, $container->get(ConnectionInterface::class));
    }

    #[Test]
    public function wireUsesRawConnectionWhenMonitoringDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        // Monitoring is off by default.
        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertNotInstanceOf(MonitoredConnection::class, $container->get(ConnectionInterface::class));
    }

    #[Test]
    public function wireFeedsProfilerEvenWhenMonitoringDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        // The request profiler is enabled while SQL monitoring stays off: turning
        // the profiler on alone must be enough to capture query timings.
        $profiler = new RequestProfiler(enabled: true);
        $container->instance(RequestProfiler::class, $profiler);

        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $connection = $container->get(ConnectionInterface::class);
        self::assertInstanceOf(MonitoredConnection::class, $connection);

        $connection->query('SELECT 1');
        $profile = $profiler->finish('GET', '/', 200);

        self::assertSame(1, $profile->queryCount);
    }

    #[Test]
    public function wireRoutesConnectionsWhenReadWriteEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true, readWriteEnabled: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertInstanceOf(RoutingConnectionManager::class, $container->get(ConnectionManagerInterface::class));
    }

    #[Test]
    public function wireUsesPlainManagerWhenReadWriteDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        // Read/write routing is off by default.
        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $manager = $container->get(ConnectionManagerInterface::class);
        self::assertNotInstanceOf(RoutingConnectionManager::class, $manager);
        self::assertInstanceOf(ConnectionManager::class, $manager);
    }

    /**
     * Routing has to happen per STATEMENT, so the connection the container hands out
     * must be one that asks the router for each query.
     *
     * `ConnectionInterface` used to be bound to `$manager->connection()` — a single
     * connection resolved before any SQL existed. `connectionForQuery()`, the only
     * method that can classify a statement, was called by nothing in the framework.
     * A deployment with routing enabled and replicas configured therefore ran every
     * SELECT on the primary.
     */
    #[Test]
    public function wireResolvesAConnectionThatRoutesEachStatement(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true, readWriteEnabled: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertInstanceOf(RoutingConnection::class, $container->get(ConnectionInterface::class));
    }

    #[Test]
    public function wireLeavesTheConnectionUnroutedWhenReadWriteIsOff(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertNotInstanceOf(RoutingConnection::class, $container->get(ConnectionInterface::class));
    }

    /**
     * `read_hosts` holds hosts, and the manager asks the connection map for names.
     * Nothing bridged the two, so the documented configuration threw
     * `Database connection "replica-1.db.internal" is not configured` on the first
     * routed read. The wiring now derives a connection per host from the primary.
     */
    #[Test]
    public function wireTurnsReadHostsIntoConnectionsTheManagerCanOpen(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(
            withDatabase: true,
            databaseConfig: '["default" => "mysql", "connections" => ["mysql" => '
                . '["driver" => "mysql", "host" => "127.0.0.1", "database" => "pulsar", "username" => "root"]], '
                . '"read_write" => ["enabled" => true, "read_hosts" => ["replica-1.db.internal"]]]',
        );
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        /** @var DatabaseConfig $dbConfig */
        $dbConfig = $container->get(DatabaseConfig::class);
        self::assertArrayHasKey('read:replica-1.db.internal', $dbConfig->connections);
        self::assertSame(
            'replica-1.db.internal',
            $dbConfig->connections['read:replica-1.db.internal']->host,
        );

        /** @var ConnectionManagerInterface $manager */
        $manager = $container->get(ConnectionManagerInterface::class);
        self::assertInstanceOf(RoutingConnectionManager::class, $manager);

        // PdoConnection is lazy: this resolves the replica without opening a socket.
        self::assertSame(
            'read:replica-1.db.internal',
            $manager->connectionForQuery('SELECT 1')->name(),
        );
    }

    /**
     * A `sqlite:` DSN has no host, so a replica derived from the primary would be the
     * same file. Wiring refuses at boot rather than reporting replica traffic that is
     * really the primary.
     */
    #[Test]
    public function wireRefusesReadHostsOnADriverWithNoHost(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(
            withDatabase: true,
            databaseConfig: '["default" => "sqlite", "connections" => ["sqlite" => '
                . '["driver" => "sqlite", "database" => ":memory:"]], '
                . '"read_write" => ["enabled" => true, "read_hosts" => ["replica-1.db.internal"]]]',
        );
        $configManager->load();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/addresses no host/');

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);
    }

    /**
     * The `query_cache` section had no consumer at all: it was parsed into a typed
     * QueryCacheConfig that no runtime object read, so `enabled`,
     * `default_ttl_seconds`, `sensitive_table_names` and `authorization_columns` were
     * four settings an operator could tune with no effect. The wiring now builds the
     * runner that reads them.
     */
    #[Test]
    public function wireBindsTheConsumerOfTheQueryCacheSection(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $container->instance(CacheInterface::class, new ArraySimpleCache());

        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $runner = $container->get(CachedQueryRunner::class);
        self::assertInstanceOf(CachedQueryRunner::class, $runner);

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);
        $connection->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $connection->execute("INSERT INTO widgets (label) VALUES ('one')");

        self::assertSame('one', $runner->query('SELECT label FROM widgets')->firstOrFail()->getString('label'));

        // Changed behind the runner: a cached read must not see it.
        $connection->execute("UPDATE widgets SET label = 'two' WHERE id = 1");

        self::assertSame('one', $runner->query('SELECT label FROM widgets')->firstOrFail()->getString('label'));
    }

    /**
     * Asking for the runner without a PSR-16 cache to store in is refused, not answered
     * with one that silently never caches.
     */
    #[Test]
    public function theQueryCacheRefusesWhenThereIsNowhereToStoreResults(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/needs a PSR-16 cache/');

        (void) $container->get(CachedQueryRunner::class);
    }

    /**
     * Multi-tenancy and failover replace `ConnectionManagerInterface` after the
     * database is wired, and their decorators carry no `connectionForQuery()`. Behind
     * one of them routing cannot happen, so a deployment that configured replicas is
     * told at boot rather than left believing its reads are being offloaded.
     */
    #[Test]
    public function wireRefusesWhenSomethingElseTookTheRoutingManagersPlace(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(
            withDatabase: true,
            databaseConfig: '["default" => "mysql", "connections" => ["mysql" => '
                . '["driver" => "mysql", "host" => "127.0.0.1", "database" => "pulsar", "username" => "root"]], '
                . '"read_write" => ["enabled" => true, "read_hosts" => ["replica-1.db.internal"]]]',
        );
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Stand in for TenancyWiring / FailoverWiring, both of which rebind the manager
        // to a decorator of their own after DatabaseWiring has run. What matters is only
        // that the replacement is not the routing manager.
        $container->instance(
            ConnectionManagerInterface::class,
            $this->createStub(ConnectionManagerInterface::class),
        );

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/rather than the routing manager/');

        (void) $container->get(ConnectionInterface::class);
    }

    /**
     * With no read hosts there is nothing to route to, so a decorator costs nothing and
     * the refusal above would only break a working deployment.
     */
    #[Test]
    public function aDecoratedManagerIsFineWhenNoReplicasAreConfigured(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true, readWriteEnabled: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $container->instance(
            ConnectionManagerInterface::class,
            $this->createStub(ConnectionManagerInterface::class),
        );

        self::assertInstanceOf(ConnectionInterface::class, $container->get(ConnectionInterface::class));
    }

    private function createConfigManager(
        bool $withDatabase,
        bool $monitorEnabled = false,
        bool $readWriteEnabled = false,
        ?string $databaseConfig = null,
    ): ConfigManager {
        $configPath = sys_get_temp_dir() . '/pulsar_db_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        if ($withDatabase) {
            if ($databaseConfig !== null) {
                file_put_contents($configPath . '/database.php', '<?php return ' . $databaseConfig . ';');
            } else {
                $monitor = $monitorEnabled ? ', "monitor" => ["enabled" => true]' : '';
                $readWrite = $readWriteEnabled ? ', "read_write" => ["enabled" => true]' : '';
                file_put_contents($configPath . '/database.php', '<?php return ["default" => "sqlite", "connections" => ["sqlite" => ["driver" => "sqlite", "database" => ":memory:"]]' . $monitor . $readWrite . '];');
            }
        }

        return new ConfigManager($configPath);
    }
}
