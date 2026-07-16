<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;
use Throwable;

use function apcu_add;
use function apcu_clear_cache;
use function apcu_dec;
use function apcu_delete;
use function apcu_exists;
use function apcu_fetch;
use function apcu_inc;
use function apcu_store;
use function is_string;

/**
 * APCu-backed cache driver.
 *
 * Uses ext-apcu for shared-memory caching within a single server.
 * Provides atomic counter support via apcu_inc/apcu_dec.
 */
#[Internal]
final class ApcuDriver extends AbstractCacheDriver
{
    public function get(string $key): ?string
    {
        $success = false;
        $value = apcu_fetch($key, $success);

        if (!$success) {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        return $value;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            $this->delete($key);

            return true;
        }

        return apcu_store($key, $value, $ttl ?? 0);
    }

    public function add(string $key, string $value, ?int $ttlSeconds): bool
    {
        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            return !apcu_exists($key);
        }

        // apcu_add stores only if the key is absent — atomic in shared memory.
        return apcu_add($key, $value, $ttl ?? 0);
    }

    public function delete(string $key): bool
    {
        apcu_delete($key);

        return true;
    }

    public function has(string $key): bool
    {
        return apcu_exists($key);
    }

    public function clear(): bool
    {
        return apcu_clear_cache();
    }

    public function increment(string $key, int $step = 1): int|false
    {
        if (!apcu_exists($key)) {
            apcu_store($key, (string) $step);

            return $step;
        }

        try {
            $success = false;
            $result = apcu_inc($key, $step, $success);

            if (!$success) {
                return false;
            }

            return $result;
        } catch (Throwable) {
            return false;
        }
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        if (!apcu_exists($key)) {
            $value = -$step;
            apcu_store($key, (string) $value);

            return $value;
        }

        try {
            $success = false;
            $result = apcu_dec($key, $step, $success);

            if (!$success) {
                return false;
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
        return 'apcu';
    }
}
