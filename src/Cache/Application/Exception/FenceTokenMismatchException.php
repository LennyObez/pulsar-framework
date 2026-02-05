<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a fencing token does not match during a fenced operation.
 */
#[Api(since: '1.0.0')]
final class FenceTokenMismatchException extends RuntimeException implements
    \Psr\Cache\CacheException,
    \Psr\SimpleCache\CacheException
{
    #[NoDiscard]
    public static function tokenMismatch(string $resource, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Fencing token mismatch for resource "%s": expected "%s", got "%s"',
            $resource,
            $expected,
            $actual,
        ));
    }

    #[NoDiscard]
    public static function tokenExpired(string $resource): self
    {
        return new self(sprintf(
            'Fencing token expired for resource "%s"',
            $resource,
        ));
    }
}
