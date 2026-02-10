<?php

/**
 * Minimal Memcached stub for Psalm static analysis.
 *
 * ext-memcached is optional (listed in composer.json suggest).
 * This stub provides just enough type information for Psalm
 * to analyze MemcachedDriver, MemcachedLock, and CacheManager.
 */
class Memcached
{
    public const RES_SUCCESS = 0;
    public const RES_NOTFOUND = 16;
    public const RES_NOTSTORED = 14;
    public const GET_EXTENDED = 2;

    /**
     * @param string $host
     * @param int $port
     * @param int $weight
     * @return bool
     */
    public function addServer(string $host, int $port, int $weight = 0): bool {}

    /**
     * @param string $key
     * @param callable|null $cache_cb
     * @param int $flags
     * @return string|array<string, mixed>|false
     */
    public function get(string $key, ?callable $cache_cb = null, int $flags = 0): string|array|false {}

    /**
     * @param float $cas_token
     * @param string $key
     * @param string $value
     * @param int $expiration
     * @return bool
     */
    public function cas(float $cas_token, string $key, string $value, int $expiration = 0): bool {}

    /**
     * @param list<string> $keys
     * @return array<string, string>|false
     */
    public function getMulti(array $keys): array|false {}

    /**
     * @param string $key
     * @param string $value
     * @param int $expiration
     * @return bool
     */
    public function set(string $key, string $value, int $expiration = 0): bool {}

    /**
     * @param array<string, string> $items
     * @param int $expiration
     * @return bool
     */
    public function setMulti(array $items, int $expiration = 0): bool {}

    /**
     * @param string $key
     * @param int $time
     * @return bool
     */
    public function delete(string $key, int $time = 0): bool {}

    /**
     * @param list<string> $keys
     * @param int $time
     * @return list<string>
     */
    public function deleteMulti(array $keys, int $time = 0): array {}

    /**
     * @param int $delay
     * @return bool
     */
    public function flush(int $delay = 0): bool {}

    /**
     * @return int
     */
    public function getResultCode(): int {}

    /**
     * @param string $key
     * @param int $offset
     * @param int $initialValue
     * @param int $expiry
     * @return int|false
     */
    public function increment(string $key, int $offset = 1, int $initialValue = 0, int $expiry = 0): int|false {}

    /**
     * @param string $key
     * @param int $offset
     * @param int $initialValue
     * @param int $expiry
     * @return int|false
     */
    public function decrement(string $key, int $offset = 1, int $initialValue = 0, int $expiry = 0): int|false {}

    /**
     * @param string $key
     * @param string $value
     * @param int $expiration
     * @return bool
     */
    public function add(string $key, string $value, int $expiration = 0): bool {}

    /**
     * @param string $key
     * @param int $expiration
     * @return bool
     */
    public function touch(string $key, int $expiration): bool {}
}
