<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Api;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;

/**
 * Cache lock contract.
 */
#[Api(since: '1.0.0')]
interface LockInterface
{
    /**
     * Attempt to acquire the lock.
     *
     * @param int $ttlSeconds Lock auto-expiration time in seconds
     * @param int $timeoutMs Maximum time to wait for the lock in milliseconds (0 = no wait)
     *
     * @return LockHandle The lock handle containing the fencing token
     *
     * @throws LockAcquisitionException If the lock cannot be acquired within the timeout
     */
    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle;

    /**
     * Release a previously acquired lock.
     *
     * @return bool True if the lock was released, false if already expired or token mismatch
     */
    public function release(LockHandle $handle): bool;

    /**
     * Refresh (extend) an acquired lock's TTL.
     *
     * @return bool True if the lock was refreshed, false if expired or token mismatch
     */
    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool;
}
