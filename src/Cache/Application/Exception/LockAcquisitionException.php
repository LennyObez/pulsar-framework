<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a cache lock cannot be acquired.
 * @api
 */
#[Api(since: '1.0.0')]
final class LockAcquisitionException extends RuntimeException implements
    \Psr\Cache\CacheException,
    \Psr\SimpleCache\CacheException
{
    #[NoDiscard]
    public static function timeout(string $resource, int $timeoutMs): self
    {
        return new self(sprintf(
            'Failed to acquire lock for resource "%s" within %d ms',
            $resource,
            $timeoutMs,
        ));
    }

    #[NoDiscard]
    public static function unavailable(string $resource, string $reason): self
    {
        return new self(sprintf(
            'Lock unavailable for resource "%s": %s',
            $resource,
            $reason,
        ));
    }
}
