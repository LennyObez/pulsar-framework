<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Api;

/**
 * Cache driver contract.
 *
 * Drivers handle raw string storage only. Serialization
 * happens in the pool layer, keeping drivers simple and testable.
 * @api
 */
#[Api(since: '1.0.0')]
interface CacheDriverInterface
{
    /**
     * Get a value by key.
     *
     * @return string|null The stored value, or null if not found or expired
     */
    public function get(string $key): ?string;

    /**
     * Get multiple values by key.
     *
     * @param list<string> $keys
     *
     * @return array<string, string|null> Key => value map (null for misses)
     */
    public function getMultiple(array $keys): array;

    /**
     * Store a value.
     *
     * @param int|null $ttlSeconds Seconds until expiration (null = no expiration, 0 or negative = expire immediately)
     */
    public function set(string $key, string $value, ?int $ttlSeconds): bool;

    /**
     * Store a value only if the key does not already exist ("add" / SETNX).
     *
     * The canonical primitive for "claim this key once" — leader election, a
     * one-shot job guard, first-writer-wins initialization — without the
     * read-then-write race of get()+set(). Atomic on backends that support a
     * native conditional store (Redis SET NX, Memcached add, apcu_add); drivers
     * without one fall back to a best-effort check-then-set (see
     * {@see AbstractCacheDriver::add()}).
     *
     * @param int|null $ttlSeconds Seconds until expiration (null = no expiration, 0 or negative = expire immediately)
     *
     * @return bool True if the value was stored, false if the key already existed (or on failure)
     */
    public function add(string $key, string $value, ?int $ttlSeconds): bool;

    /**
     * Store multiple values.
     *
     * @param array<string, string> $values Key => value map
     * @param int|null $ttlSeconds Seconds until expiration (null = no expiration, 0 or negative = expire immediately)
     */
    public function setMultiple(array $values, ?int $ttlSeconds): bool;

    /**
     * Delete a value by key.
     */
    public function delete(string $key): bool;

    /**
     * Delete multiple values by key.
     *
     * @param list<string> $keys
     */
    public function deleteMultiple(array $keys): bool;

    /**
     * Check whether a key exists and is not expired.
     */
    public function has(string $key): bool;

    /**
     * Remove all entries from this driver's store.
     */
    public function clear(): bool;

    /**
     * Atomically increment a counter.
     *
     * @return int|false The new value, or false if the operation is unsupported or fails
     */
    public function increment(string $key, int $step = 1): int|false;

    /**
     * Atomically decrement a counter.
     *
     * @return int|false The new value, or false if the operation is unsupported or fails
     */
    public function decrement(string $key, int $step = 1): int|false;

    /**
     * Get the driver's capabilities.
     */
    public function capabilities(): CacheDriverCapabilities;

    /**
     * Get the driver's human-readable name.
     */
    public function name(): string;
}
