<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use DateInterval;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Console\Command\DbFailoverWatchCommand;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\FailoverWiring;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Failover\FailoverConfig;
use Pulsar\Database\Failover\FailoverConnectionManager;
use Pulsar\Database\Failover\FailoverStateStore;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function sys_get_temp_dir;

final class FailoverWiringTest extends TestCase
{
    #[Test]
    public function wiresStoreDecoratorAndDaemonWhenEnabledWithCache(): void
    {
        $container = $this->container(failoverEnabled: true, withCache: true);

        $this->wire($container);

        self::assertTrue($container->has(FailoverStateStore::class), 'shared state store is registered');
        self::assertTrue($container->has(DbFailoverWatchCommand::class), 'failover watch daemon is registered');
        self::assertInstanceOf(
            FailoverConnectionManager::class,
            $container->get(ConnectionManagerInterface::class),
            'the connection manager is decorated for failover',
        );
    }

    #[Test]
    public function isNoOpWhenFailoverDisabled(): void
    {
        $container = $this->container(failoverEnabled: false, withCache: true);

        $this->wire($container);

        self::assertFalse($container->has(FailoverStateStore::class));
        self::assertNotInstanceOf(
            FailoverConnectionManager::class,
            $container->get(ConnectionManagerInterface::class),
            'the connection manager is left untouched when failover is disabled',
        );
    }

    #[Test]
    public function isInertWithoutACache(): void
    {
        // Failover needs the cache for cross-process state; without it the
        // feature degrades rather than half-wiring.
        $container = $this->container(failoverEnabled: true, withCache: false);

        $this->wire($container);

        self::assertFalse($container->has(FailoverStateStore::class));
        self::assertNotInstanceOf(
            FailoverConnectionManager::class,
            $container->get(ConnectionManagerInterface::class),
        );
    }

    private function container(bool $failoverEnabled, bool $withCache): Container
    {
        $sqlite = new ConnectionConfig(
            name: 'sqlite',
            driver: Driver::SQLite,
            host: 'db.primary.internal',
            port: 0,
            database: ':memory:',
            username: '',
            password: '',
            charset: 'utf8',
            collation: 'utf8',
            options: [],
        );

        $dbConfig = new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: ['sqlite' => $sqlite],
            migrationsTable: 'migrations',
            migrationsPath: '',
            failover: new FailoverConfig(enabled: $failoverEnabled, strategy: 'dns'),
        );

        $container = new Container();
        $container->instance(DatabaseConfig::class, $dbConfig);

        $manager = ConnectionManager::fromConfig($dbConfig);
        $container->instance(ConnectionManager::class, $manager);
        $container->instance(ConnectionManagerInterface::class, $manager);

        if ($withCache) {
            $container->instance(CacheInterface::class, $this->cache());
        }

        return $container;
    }

    private function wire(Container $container): void
    {
        new FailoverWiring()->wire(
            $container,
            new ConfigManager(sys_get_temp_dir()),
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );
    }

    private function cache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            /**
             * @param iterable<string> $keys
             * @return iterable<string, mixed>
             */
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $result = [];

                foreach ($keys as $key) {
                    $result[$key] = $this->store[$key] ?? $default;
                }

                return $result;
            }

            /**
             * @param iterable<string, mixed> $values
             */
            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->store[$key] = $value;
                }

                return true;
            }

            /**
             * @param iterable<string> $keys
             */
            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    unset($this->store[$key]);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->store[$key]);
            }
        };
    }
}
