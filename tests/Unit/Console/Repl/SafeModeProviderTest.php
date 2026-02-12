<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Console\Repl\CaptureOnlyQueueDriver;
use Pulsar\Console\Repl\ReadOnlyCachePool;
use Pulsar\Console\Repl\ReadOnlyConnection;
use Pulsar\Console\Repl\ReadOnlySimpleCache;
use Pulsar\Console\Repl\ReadOnlyStorageAdapter;
use Pulsar\Console\Repl\SafeModeProvider;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Storage\StorageAdapterInterface;

#[CoversClass(SafeModeProvider::class)]
final class SafeModeProviderTest extends TestCase
{
    #[Test]
    public function applyWrapsConnection(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $container = $this->createContainerWithBindings([
            ConnectionInterface::class => $connection,
        ]);

        $provider = new SafeModeProvider($container);
        $provider->apply();

        /** @var ReadOnlyConnection $wrapped */
        $wrapped = $container->get(ConnectionInterface::class);
        self::assertInstanceOf(ReadOnlyConnection::class, $wrapped);
    }

    #[Test]
    public function applyWrapsQueueDriver(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $container = $this->createContainerWithBindings([
            QueueDriverInterface::class => $driver,
        ]);

        $provider = new SafeModeProvider($container);
        $provider->apply();

        /** @var CaptureOnlyQueueDriver $wrapped */
        $wrapped = $container->get(QueueDriverInterface::class);
        self::assertInstanceOf(CaptureOnlyQueueDriver::class, $wrapped);
    }

    #[Test]
    public function applyWrapsStorageAdapter(): void
    {
        $adapter = $this->createStub(StorageAdapterInterface::class);
        $container = $this->createContainerWithBindings([
            StorageAdapterInterface::class => $adapter,
        ]);

        $provider = new SafeModeProvider($container);
        $provider->apply();

        /** @var ReadOnlyStorageAdapter $wrapped */
        $wrapped = $container->get(StorageAdapterInterface::class);
        self::assertInstanceOf(ReadOnlyStorageAdapter::class, $wrapped);
    }

    #[Test]
    public function applyWrapsSimpleCache(): void
    {
        if (!interface_exists(CacheInterface::class)) {
            self::markTestSkipped('PSR-16 SimpleCache interface not available.');
        }

        $cache = $this->createStub(CacheInterface::class);
        $container = $this->createContainerWithBindings([
            CacheInterface::class => $cache,
        ]);

        $provider = new SafeModeProvider($container);
        $provider->apply();

        /** @var ReadOnlySimpleCache $wrapped */
        $wrapped = $container->get(CacheInterface::class);
        self::assertInstanceOf(ReadOnlySimpleCache::class, $wrapped);
    }

    #[Test]
    public function applyWrapsCachePool(): void
    {
        if (!interface_exists(CacheItemPoolInterface::class)) {
            self::markTestSkipped('PSR-6 CacheItemPool interface not available.');
        }

        $pool = $this->createStub(CacheItemPoolInterface::class);
        $container = $this->createContainerWithBindings([
            CacheItemPoolInterface::class => $pool,
        ]);

        $provider = new SafeModeProvider($container);
        $provider->apply();

        /** @var ReadOnlyCachePool $wrapped */
        $wrapped = $container->get(CacheItemPoolInterface::class);
        self::assertInstanceOf(ReadOnlyCachePool::class, $wrapped);
    }

    #[Test]
    public function applySkipsMissingBindings(): void
    {
        $container = $this->createContainerWithBindings([]);

        $provider = new SafeModeProvider($container);
        // Should not throw
        $provider->apply();

        self::assertFalse($container->has(ConnectionInterface::class));
    }

    /**
     * @param array<string, object> $bindings
     *
     * @return Stub&ContainerInterface
     */
    private function createContainerWithBindings(array $bindings): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);

        $container->method('has')
            ->willReturnCallback(fn(string $id): bool => isset($bindings[$id]));

        $container->method('get')
            ->willReturnCallback(function (string $id) use (&$bindings): object {
                return $bindings[$id];
            });

        $container->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$bindings): void {
                $bindings[$id] = $instance;
            });

        return $container;
    }
}
