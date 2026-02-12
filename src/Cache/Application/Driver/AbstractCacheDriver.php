<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;

/**
 * Base class for cache drivers providing shared TTL normalization and batch defaults.
 */
#[Internal]
abstract class AbstractCacheDriver implements CacheDriverInterface
{
    /**
     * Normalize TTL to seconds.
     *
     * Returns null for "no expiration", or 0 for "expire immediately".
     *
     * @return int|null null = no expiration, 0 = expire immediately
     */
    protected function normalizeTtl(?int $ttlSeconds): ?int
    {
        if ($ttlSeconds === null) {
            return null;
        }

        if ($ttlSeconds <= 0) {
            return 0;
        }

        return $ttlSeconds;
    }

    /**
     * Check if TTL means the item should not be stored.
     */
    protected function isExpiredTtl(?int $ttlSeconds): bool
    {
        return $ttlSeconds !== null && $ttlSeconds <= 0;
    }

    public function getMultiple(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function setMultiple(array $values, ?int $ttlSeconds): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttlSeconds)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function increment(string $key, int $step = 1): int|false
    {
        return false;
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return false;
    }
}
