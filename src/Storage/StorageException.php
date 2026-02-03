<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for storage operation failures.
 */
#[Api]
final class StorageException extends RuntimeException
{
    public static function objectNotFound(string $key): self
    {
        return new self(sprintf('Storage object not found: "%s"', $key));
    }

    public static function writeFailed(string $key, string $reason): self
    {
        return new self(sprintf('Failed to write storage object "%s": %s', $key, $reason));
    }

    public static function deleteFailed(string $key, string $reason): self
    {
        return new self(sprintf('Failed to delete storage object "%s": %s', $key, $reason));
    }

    public static function readFailed(string $key, string $reason): self
    {
        return new self(sprintf('Failed to read storage object "%s": %s', $key, $reason));
    }

    public static function invalidKey(string $key, string $reason): self
    {
        return new self(sprintf('Invalid storage key "%s": %s', $key, $reason));
    }

    public static function diskNotFound(string $name): self
    {
        return new self(sprintf('Storage disk not found: "%s"', $name));
    }

    public static function connectionFailed(string $reason): self
    {
        return new self(sprintf('Storage connection failed: %s', $reason));
    }
}
