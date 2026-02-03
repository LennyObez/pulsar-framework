<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Pulsar\Api\Internal;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for framework cache operations.
 */
#[Internal]
final class CacheException extends RuntimeException
{
    public static function writeFailure(string $path, string $reason): self
    {
        return new self(sprintf('Failed to write cache file "%s": %s', $path, $reason));
    }

    public static function corruptedCache(string $path, string $reason): self
    {
        return new self(sprintf('Corrupted cache file "%s": %s', $path, $reason));
    }

    public static function signatureInvalid(string $path): self
    {
        return new self(sprintf('HMAC signature verification failed for cache file "%s"', $path));
    }

    public static function staleCache(string $reason): self
    {
        return new self(sprintf('Stale cache detected: %s', $reason));
    }

    public static function lockFailed(string $path): self
    {
        return new self(sprintf('Failed to acquire cache lock at "%s"', $path));
    }

    public static function directoryInvalid(string $path, string $reason): self
    {
        return new self(sprintf('Invalid cache directory "%s": %s', $path, $reason));
    }
}
