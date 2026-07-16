<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;

/**
 * Optional driver capability: delete exactly the keys under a prefix.
 *
 * Backends with an enumeration primitive (Redis SCAN, APCu iterator, an
 * in-memory map) can scope clear() to one pool's namespace instead of flushing
 * a whole database or server shared with other pools and applications. The
 * PrefixedCacheDecorator uses this for its clear(); drivers without an
 * enumeration primitive (Memcached) or whose storage hashes keys
 * (FilesystemDriver) do not implement it, and a prefixed clear() on them fails
 * loudly rather than silently flushing beyond its scope.
 */
#[Internal]
interface PrefixClearableInterface
{
    /**
     * Delete every key that starts with the given prefix.
     */
    public function clearByPrefix(string $prefix): bool;
}
