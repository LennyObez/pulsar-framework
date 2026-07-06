<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\CacheKeyValidator;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Mail\Webhook\CacheBackedDeduplicationStore;

#[CoversClass(CacheBackedDeduplicationStore::class)]
final class CacheBackedDeduplicationStoreTest extends TestCase
{
    #[Test]
    public function storesAndDetectsAnEvent(): void
    {
        $store = new CacheBackedDeduplicationStore($this->cache());

        self::assertFalse($store->has('evt-1'));
        $store->store('evt-1');
        self::assertTrue($store->has('evt-1'));
    }

    #[Test]
    public function isolatesEventsByTenant(): void
    {
        $store = new CacheBackedDeduplicationStore($this->cache());

        $store->store('evt-1', 'tenant-a');

        self::assertTrue($store->has('evt-1', 'tenant-a'));
        self::assertFalse($store->has('evt-1', 'tenant-b'), 'a different tenant has not seen the event');
    }

    #[Test]
    public function usesCacheSafeKeys(): void
    {
        // The fake cache validates keys with the real CacheKeyValidator, so a
        // reserved character (e.g. ':') in the key would throw here.
        $store = new CacheBackedDeduplicationStore($this->cache());

        $store->store('evt/with:reserved@chars{}', 'tenant:a');

        self::assertTrue($store->has('evt/with:reserved@chars{}', 'tenant:a'));
    }

    #[Test]
    public function cleanupIsANoOpAndDoesNotDropState(): void
    {
        $store = new CacheBackedDeduplicationStore($this->cache());
        $store->store('evt-1');

        $store->cleanup(7);

        self::assertTrue($store->has('evt-1'), 'cleanup must not reopen the replay window');
    }

    /**
     * In-memory cache that enforces the same key rules as the real driver, so
     * unsafe keys fail the test rather than passing silently.
     */
    private function cache(): TaggedCacheInterface
    {
        return new class implements TaggedCacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key): mixed
            {
                CacheKeyValidator::validate($key);

                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                CacheKeyValidator::validate($key);
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function invalidateTag(string $tag): void {}

            public function invalidateTags(array $tags): void {}
        };
    }
}
