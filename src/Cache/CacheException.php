<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for framework cache operations.
 */
#[Api(since: '1.0.0')]
final class CacheException extends RuntimeException
{
    #[NoDiscard]
    public static function writeFailure(string $path, string $reason): self
    {
        return new self(sprintf('Failed to write cache file "%s": %s', $path, $reason));
    }

    #[NoDiscard]
    public static function corruptedCache(string $path, string $reason): self
    {
        return new self(sprintf('Corrupted cache file "%s": %s', $path, $reason));
    }

    #[NoDiscard]
    public static function signatureInvalid(string $path): self
    {
        return new self(sprintf('HMAC signature verification failed for cache file "%s"', $path));
    }

    #[NoDiscard]
    public static function staleCache(string $reason): self
    {
        return new self(sprintf('Stale cache detected: %s', $reason));
    }

    #[NoDiscard]
    public static function lockFailed(string $path): self
    {
        return new self(sprintf('Failed to acquire cache lock at "%s"', $path));
    }

    #[NoDiscard]
    public static function directoryInvalid(string $path, string $reason): self
    {
        return new self(sprintf('Invalid cache directory "%s": %s', $path, $reason));
    }
}
