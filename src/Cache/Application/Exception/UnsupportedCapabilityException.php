<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a cache operation requires a capability the driver does not support.
 * @api
 */
#[Api(since: '1.0.0')]
final class UnsupportedCapabilityException extends RuntimeException implements
    \Psr\Cache\CacheException,
    \Psr\SimpleCache\CacheException
{
    #[NoDiscard]
    public static function strictTagsUnsupported(string $driver): self
    {
        return new self(sprintf(
            'Driver "%s" does not support strict tag invalidation. Use a driver with atomic increment (Redis, Database) or set tags.strategy to "best_effort".',
            $driver,
        ));
    }

    #[NoDiscard]
    public static function fencingUnsupported(string $driver): self
    {
        return new self(sprintf(
            'Driver "%s" does not support fencing tokens. Use Redis or Database driver for fenced locks.',
            $driver,
        ));
    }

    #[NoDiscard]
    public static function atomicIncrementOnEncryptedPool(): self
    {
        return new self('Atomic increment/decrement is not supported on encrypted cache pools');
    }

    #[NoDiscard]
    public static function prefixScopedClearUnsupported(string $driver): self
    {
        return new self(sprintf(
            'Driver "%s" cannot enumerate keys, so clear() on a prefixed pool cannot be scoped to the prefix. '
            . 'Refusing to flush the whole shared backend: use a dedicated backend for this pool, '
            . 'a driver with key enumeration (Redis, APCu), or invalidate via tags instead.',
            $driver,
        ));
    }
}
