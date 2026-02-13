<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Exception\UnsupportedCapabilityException;
use Pulsar\Cache\Application\Lock\LockInterface;

/**
 * Application cache manager: public API for pool resolution.
 */
#[Api(since: '1.0.0')]
interface CacheManagerInterface
{
    /**
     * Get a PSR-6 cache pool by name.
     *
     * @param string|null $name Pool name (null = default pool)
     *
     * @return CacheItemPoolInterface The resolved PSR-6 cache pool
     *
     * @throws CacheException If the pool is not configured
     */
    public function pool(?string $name = null): CacheItemPoolInterface;

    /**
     * Get a PSR-16 simple cache by name.
     *
     * @param string|null $name Pool name (null = default pool)
     *
     * @return CacheInterface The resolved PSR-16 simple cache adapter
     *
     * @throws CacheException If the pool is not configured
     */
    public function simple(?string $name = null): CacheInterface;

    /**
     * Get a tagged cache for a pool.
     *
     * @param string|null $name Pool name (null = default pool)
     *
     * @return TaggedCacheInterface The resolved tagged cache for tag-based invalidation
     *
     * @throws CacheException If the pool is not configured
     * @throws UnsupportedCapabilityException If strict tags are requested but unsupported
     */
    public function tagged(?string $name = null): TaggedCacheInterface;

    /**
     * Get a lock implementation for a pool's driver.
     *
     * @param string|null $name Pool name (null = default pool)
     *
     * @return LockInterface The resolved distributed lock for the pool's backend
     *
     * @throws CacheException If the pool is not configured or lock resolution fails
     */
    public function lock(?string $name = null): LockInterface;

    /**
     * Get the underlying driver for a pool.
     *
     * @param string|null $name Pool name (null = default pool)
     *
     * @return CacheDriverInterface The resolved low-level cache driver
     *
     * @throws CacheException If the pool is not configured or encryption is unavailable
     */
    public function driver(?string $name = null): CacheDriverInterface;
}
