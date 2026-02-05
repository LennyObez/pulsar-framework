<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * General cache exception for application cache operations.
 */
#[Api(since: '1.0.0')]
final class CacheException extends RuntimeException implements
    \Psr\Cache\CacheException,
    \Psr\SimpleCache\CacheException
{
    #[NoDiscard]
    public static function driverError(string $driver, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Cache driver "%s" error: %s', $driver, $reason),
            previous: $previous,
        );
    }

    #[NoDiscard]
    public static function poolNotConfigured(string $pool): self
    {
        return new self(sprintf('Cache pool "%s" is not configured', $pool));
    }

    #[NoDiscard]
    public static function serializationFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Cache serialization failed: %s', $reason),
            previous: $previous,
        );
    }

    #[NoDiscard]
    public static function invalidTtl(string $reason): self
    {
        return new self(sprintf('Invalid cache TTL: %s', $reason));
    }

    #[NoDiscard]
    public static function decryptionFailed(string $pool, string $key, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Cache decryption failed for pool "%s", key "%s"', $pool, $key),
            previous: $previous,
        );
    }

    #[NoDiscard]
    public static function aadMismatch(string $pool, string $key): self
    {
        return new self(
            sprintf('Cache AAD verification failed for pool "%s", key "%s" — possible tampering or pool migration', $pool, $key),
        );
    }

    #[NoDiscard]
    public static function unknownKeyId(string $pool, string $keyId): self
    {
        return new self(
            sprintf('Cache encryption key ID "%s" is unknown for pool "%s" — key rotation may be incomplete', $pool, $keyId),
        );
    }

    #[NoDiscard]
    public static function encryptionUnavailable(string $poolName): self
    {
        return new self(
            sprintf('Pool "%s" requires encryption but no master key is available', $poolName),
        );
    }

    #[NoDiscard]
    public static function databaseConnectionRequired(string $driver): self
    {
        return new self(
            sprintf('Cache driver "%s" requires a database connection but none is available', $driver),
        );
    }
}
