<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Failover;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Database\Failover\CacheBackedFailoverStateStore;

#[CoversClass(CacheBackedFailoverStateStore::class)]
final class CacheBackedFailoverStateStoreTest extends TestCase
{
    #[Test]
    public function noFailoverByDefault(): void
    {
        $store = new CacheBackedFailoverStateStore($this->cache());

        self::assertNull($store->currentEndpoint());
    }

    #[Test]
    public function recordsAndReadsBackTheEndpoint(): void
    {
        $store = new CacheBackedFailoverStateStore($this->cache());

        $store->recordFailover('10.0.0.2');

        self::assertSame('10.0.0.2', $store->currentEndpoint());
    }

    #[Test]
    public function clearRevertsToNoFailover(): void
    {
        $store = new CacheBackedFailoverStateStore($this->cache());
        $store->recordFailover('10.0.0.2');

        $store->clear();

        self::assertNull($store->currentEndpoint());
    }

    #[Test]
    public function emptyEndpointIsIgnored(): void
    {
        $store = new CacheBackedFailoverStateStore($this->cache());

        $store->recordFailover('');

        self::assertNull($store->currentEndpoint());
    }

    #[Test]
    public function stateIsSharedThroughTheCacheAcrossInstances(): void
    {
        // Two stores over the same cache model the daemon (writer) and a web
        // worker (reader) sharing the promoted endpoint across processes.
        $cache = $this->cache();
        $writer = new CacheBackedFailoverStateStore($cache);
        $reader = new CacheBackedFailoverStateStore($cache);

        $writer->recordFailover('10.0.0.9');

        self::assertSame('10.0.0.9', $reader->currentEndpoint());
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
