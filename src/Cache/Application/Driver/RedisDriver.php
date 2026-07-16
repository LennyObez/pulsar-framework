<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;
use Redis;
use Throwable;

use function array_fill_keys;
use function array_keys;
use function is_array;
use function is_string;

/**
 * Redis-backed cache driver.
 *
 * Uses ext-redis for high-performance key-value caching with native
 * TTL support, pipelining for batch operations, and atomic counters.
 */
#[Internal]
final class RedisDriver extends AbstractCacheDriver
{
    public function __construct(
        private readonly Redis $redis,
    ) {}

    public function get(string $key): ?string
    {
        $value = $this->redis->get($key);

        if (!is_string($value)) {
            return null;
        }

        return $value;
    }

    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $values = $this->redis->mget($keys);

        /** @psalm-suppress TypeDoesNotContainType: ext-redis mget() can return false on connection failure */
        if (!is_array($values)) {
            return array_fill_keys($keys, null);
        }

        $result = [];

        foreach ($keys as $i => $key) {
            $value = $values[$i] ?? false;
            $result[$key] = is_string($value) ? $value : null;
        }

        return $result;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            $this->delete($key);

            return true;
        }

        if ($ttl !== null) {
            return $this->redis->setex($key, $ttl, $value);
        }

        return $this->redis->set($key, $value);
    }

    public function add(string $key, string $value, ?int $ttlSeconds): bool
    {
        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            return !$this->has($key);
        }

        // SET key value NX [EX ttl]: stores only when the key is absent and
        // returns false when the NX condition fails — atomic in one round-trip.
        $options = $ttl !== null ? ['nx', 'ex' => $ttl] : ['nx'];

        try {
            return $this->redis->set($key, $value, $options) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public function setMultiple(array $values, ?int $ttlSeconds): bool
    {
        if ($values === []) {
            return true;
        }

        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            return $this->deleteMultiple(array_keys($values));
        }

        $pipe = $this->redis->pipeline();

        foreach ($values as $key => $value) {
            if ($ttl !== null) {
                $pipe->setex($key, $ttl, $value);
            } else {
                $pipe->set($key, $value);
            }
        }

        $results = $pipe->exec();

        if ($results === false) {
            return false;
        }

        return array_all($results, static fn(mixed $result): bool => $result !== false);
    }

    public function delete(string $key): bool
    {
        $this->redis->del($key);

        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $this->redis->del(...$keys);

        return true;
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    public function clear(): bool
    {
        return $this->redis->flushDB();
    }

    public function increment(string $key, int $step = 1): int|false
    {
        try {
            return $this->redis->incrBy($key, $step);
        } catch (Throwable) {
            return false;
        }
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        try {
            return $this->redis->decrBy($key, $step);
        } catch (Throwable) {
            return false;
        }
    }

    public function capabilities(): CacheDriverCapabilities
    {
        return new CacheDriverCapabilities(
            supportsTagsStrict: true,
            supportsLocksFencing: true,
            supportsBinary: true,
            supportsAtomicIncrement: true,
        );
    }

    public function name(): string
    {
        return 'redis';
    }
}
