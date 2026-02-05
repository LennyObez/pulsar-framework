<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Base exception for ORM operations.
 */
#[Api(since: '1.0.0')]
class OrmException extends RuntimeException
{
    #[NoDiscard]
    public static function operationFailed(string $operation, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('ORM operation failed: %s', $operation),
            0,
            $previous,
        );
    }

    #[NoDiscard]
    public static function invalidConfiguration(string $message): self
    {
        return new self(sprintf('Invalid ORM configuration: %s', $message));
    }

    #[NoDiscard]
    public static function unsupportedDriver(string $driver): self
    {
        return new self(sprintf('Unsupported database driver: %s', $driver));
    }
}
