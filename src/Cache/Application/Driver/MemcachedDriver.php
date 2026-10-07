<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Memcached;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;
use Throwable;

use function array_fill_keys;
use function array_keys;
use function is_array;
use function is_string;
use function time;

/**
 * Memcached-backed cache driver.
 *
 * Uses ext-memcached for distributed in-memory caching with native
 * TTL support and atomic counter operations.
 *
 * Declares {@see GenerationClearableInterface}: Memcached cannot enumerate keys,
 * so a prefixed pool clears by bumping a generation counter, and Memcached's LRU
 * eviction reclaims the orphaned previous generation — safe here, unsafe on a
 * store without eviction.
 */
#[Internal]
final class MemcachedDriver extends AbstractCacheDriver implements GenerationClearableInterface
{
    public function __construct(
        private readonly Memcached $memcached,
    ) {}

    public function get(string $key): ?string
    {
        $value = $this->memcached->get($key);

        if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
            return null;
        }

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

        // getMulti() is typed as mixed upstream: it answers with a key => value map,
        // or false when the whole fetch failed. Anything else means the driver is not
        // the extension it claims to be, and reading offsets off it would be silent
        // nulls for every key — a cache that always misses rather than one that errors.
        $values = $this->memcached->getMulti($keys);

        if (!is_array($values)) {
            return array_fill_keys($keys, null);
        }

        $result = [];

        foreach ($keys as $key) {
            $result[$key] = Coerce::nullableString($values[$key] ?? null);
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

        // Memcached interprets TTL > 30 days as Unix timestamp
        $expiration = $ttl !== null ? time() + $ttl : 0;

        return $this->memcached->set($key, $value, $expiration);
    }

    public function add(string $key, string $value, ?int $ttlSeconds): bool
    {
        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            return !$this->has($key);
        }

        // Memcached::add stores only if the key is absent — atomic server-side.
        $expiration = $ttl !== null ? time() + $ttl : 0;

        return $this->memcached->add($key, $value, $expiration);
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

        $expiration = $ttl !== null ? time() + $ttl : 0;

        return $this->memcached->setMulti($values, $expiration);
    }

    public function delete(string $key): bool
    {
        $this->memcached->delete($key);

        // Treat "not found" as success: the key is already gone
        return $this->memcached->getResultCode() === Memcached::RES_SUCCESS
            || $this->memcached->getResultCode() === Memcached::RES_NOTFOUND;
    }

    public function deleteMultiple(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $this->memcached->deleteMulti($keys);

        return true;
    }

    public function has(string $key): bool
    {
        $this->memcached->get($key);

        return $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND;
    }

    public function clear(): bool
    {
        return $this->memcached->flush();
    }

    public function increment(string $key, int $step = 1): int|false
    {
        if ($step < 0) {
            return $this->decrement($key, -$step);
        }

        try {
            $result = $this->memcached->increment($key, $step);

            if ($result === false && $this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
                // Initialize atomically: add() stores only if the key is still
                // absent, so a concurrent initializer cannot be clobbered (the
                // previous set() overwrote whatever a racing process had
                // already counted). If we lose the init race, the key now
                // exists — increment it like any other hit.
                if ($this->memcached->add($key, (string) $step, 0)) {
                    return $step;
                }

                return $this->memcached->increment($key, $step);
            }

            return $result;
        } catch (Throwable) {
            return false;
        }
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        if ($step < 0) {
            return $this->increment($key, -$step);
        }

        try {
            $result = $this->memcached->decrement($key, $step);

            if ($result === false && $this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
                // Initialize key to negative step
                $value = -$step;
                $this->memcached->set($key, (string) $value, 0);

                return $value;
            }

            return $result;
        } catch (Throwable) {
            return false;
        }
    }

    public function capabilities(): CacheDriverCapabilities
    {
        return new CacheDriverCapabilities(
            supportsBinary: true,
            supportsAtomicIncrement: true,
        );
    }

    public function name(): string
    {
        return 'memcached';
    }
}
