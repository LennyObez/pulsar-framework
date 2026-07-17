<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Internal;

/**
 * Namespaces every lock resource under a pool's key prefix.
 *
 * Locks resolve from the raw backend connection, below the data decorator
 * stack, so without this two pools — or two applications — sharing one Redis
 * with distinct data prefixes would still collide on lock resource names: one
 * app's single-flight lock on a logical key would block another's on the same
 * key (contention / cross-application DoS) even though their data is isolated.
 * Prepending the same prefix the data keys use gives lock resources the same
 * isolation.
 *
 * The returned {@see LockHandle} already carries the prefixed resource name, so
 * release() and refresh() pass it straight through — the prefix is applied
 * exactly once, at acquire time.
 */
#[Internal]
final readonly class PrefixedLock implements LockInterface
{
    public function __construct(
        private LockInterface $inner,
        private string $prefix,
    ) {}

    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
    {
        return $this->inner->acquire($this->prefix . $resource, $ttlSeconds, $timeoutMs);
    }

    public function release(LockHandle $handle): bool
    {
        return $this->inner->release($handle);
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
    {
        return $this->inner->refresh($handle, $ttlSeconds);
    }
}
