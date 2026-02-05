<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Storage\StorageAdapterInterface;

use function interface_exists;

/**
 * Applies safe-mode decorators to container bindings.
 *
 * Wraps database connections, queue drivers, storage adapters, and PSR cache
 * implementations with read-only decorators that block mutations.
 */
#[Internal]
final readonly class SafeModeProvider
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    /**
     * Replace mutable container bindings with safe-mode wrappers.
     */
    public function apply(): void
    {
        $this->wrapConnection();
        $this->wrapQueueDriver();
        $this->wrapStorageAdapter();
        $this->wrapSimpleCache();
        $this->wrapCachePool();
    }

    private function wrapConnection(): void
    {
        if (!$this->container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $this->container->get(ConnectionInterface::class);
        $this->container->instance(ConnectionInterface::class, new ReadOnlyConnection($connection));
    }

    private function wrapQueueDriver(): void
    {
        if (!$this->container->has(QueueDriverInterface::class)) {
            return;
        }

        /** @var QueueDriverInterface $driver */
        $driver = $this->container->get(QueueDriverInterface::class);
        $this->container->instance(QueueDriverInterface::class, new CaptureOnlyQueueDriver($driver));
    }

    private function wrapStorageAdapter(): void
    {
        if (!$this->container->has(StorageAdapterInterface::class)) {
            return;
        }

        /** @var StorageAdapterInterface $adapter */
        $adapter = $this->container->get(StorageAdapterInterface::class);
        $this->container->instance(StorageAdapterInterface::class, new ReadOnlyStorageAdapter($adapter));
    }

    private function wrapSimpleCache(): void
    {
        /** @var class-string $interface */
        $interface = CacheInterface::class;

        if (!interface_exists($interface) || !$this->container->has($interface)) {
            return;
        }

        /** @var CacheInterface $cache */
        $cache = $this->container->get($interface);
        $this->container->instance($interface, new ReadOnlySimpleCache($cache));
    }

    private function wrapCachePool(): void
    {
        /** @var class-string $interface */
        $interface = CacheItemPoolInterface::class;

        if (!interface_exists($interface) || !$this->container->has($interface)) {
            return;
        }

        /** @var CacheItemPoolInterface $pool */
        $pool = $this->container->get($interface);
        $this->container->instance($interface, new ReadOnlyCachePool($pool));
    }
}
