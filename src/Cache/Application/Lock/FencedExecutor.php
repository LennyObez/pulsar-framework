<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Api;
use Pulsar\Cache\Application\Exception\FenceTokenMismatchException;

/**
 * Enforces fencing token validation before executing operations.
 *
 * Provides lock liveness verification before callback execution by refreshing
 * the lock to confirm the caller still holds it. However, the lock can still
 * expire during callback execution: this class cannot prevent that.
 *
 * For true distributed fencing, the downstream resource (database, API, etc.)
 * must independently validate the fencing token at write time. This executor
 * reduces: but does not eliminate: the window for stale-lock writes.
 */
#[Api(since: '1.0.0')]
final readonly class FencedExecutor
{
    public function __construct(
        private LockInterface $lock,
    ) {}

    /**
     * Execute a callback within a fencing guarantee.
     *
     * Verifies that the lock handle's token is still valid before
     * invoking the callback. The handle is passed to the callback
     * for reference.
     *
     * @template T
     * @param callable(LockHandle): T $callback
     * @return T
     *
     * @throws FenceTokenMismatchException If the token has expired
     */
    public function execute(LockHandle $handle, callable $callback): mixed
    {
        // Verify the lock is still held by refreshing with a minimal TTL extension
        if (!$this->lock->refresh($handle, $handle->ttlSeconds)) {
            throw FenceTokenMismatchException::tokenExpired($handle->resource);
        }

        return $callback($handle);
    }
}
