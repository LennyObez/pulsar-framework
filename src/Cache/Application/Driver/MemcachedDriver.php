<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Memcached;
use Pulsar\Api\Internal;
use Throwable;

use function array_combine;
use function array_keys;
use function array_map;
use function is_string;
use function time;

/**
 * Memcached-backed cache driver.
 *
 * Uses ext-memcached for distributed in-memory caching with native
 * TTL support and atomic counter operations.
 */
#[Internal]
final class MemcachedDriver extends AbstractCacheDriver
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

        $values = $this->memcached->getMulti($keys);

        if ($values === false) {
            return array_combine($keys, array_map(static fn(string $_): null => null, $keys));
        }

        $result = [];

        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
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

        // Memcached interprets TTL > 30 days as Unix timestamp
        $expiration = $ttl !== null ? time() + $ttl : 0;

        return $this->memcached->set($key, $value, $expiration);
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

        // Treat "not found" as success — the key is already gone
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
                // Initialize key to the step value
                $this->memcached->set($key, (string) $step, 0);

                return $step;
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
