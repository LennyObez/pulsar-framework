<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for storage operation failures.
 * @api
 */
#[Api(since: '1.0.0')]
final class StorageException extends RuntimeException
{
    #[NoDiscard]
    public static function objectNotFound(string $key): self
    {
        return new self(sprintf('Storage object not found: "%s"', $key));
    }

    #[NoDiscard]
    public static function writeFailed(string $key, string $reason): self
    {
        return new self(sprintf('Failed to write storage object "%s": %s', $key, $reason));
    }

    #[NoDiscard]
    public static function deleteFailed(string $key, string $reason): self
    {
        return new self(sprintf('Failed to delete storage object "%s": %s', $key, $reason));
    }

    #[NoDiscard]
    public static function readFailed(string $key, string $reason): self
    {
        return new self(sprintf('Failed to read storage object "%s": %s', $key, $reason));
    }

    #[NoDiscard]
    public static function invalidKey(string $key, string $reason): self
    {
        return new self(sprintf('Invalid storage key "%s": %s', $key, $reason));
    }

    #[NoDiscard]
    public static function diskNotFound(string $name): self
    {
        return new self(sprintf('Storage disk not found: "%s"', $name));
    }

    #[NoDiscard]
    public static function connectionFailed(string $reason): self
    {
        return new self(sprintf('Storage connection failed: %s', $reason));
    }
}
