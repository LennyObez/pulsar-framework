<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Exception;

use Pulsar\Api\Api;
use RuntimeException as BaseRuntimeException;

use function sprintf;

/**
 * Runtime-specific exceptions with static factory methods.
 */
#[Api(since: '1.0.0')]
final class RuntimeException extends BaseRuntimeException
{
    public static function socketError(string $message): self
    {
        return new self(sprintf('Socket error: %s', $message));
    }

    public static function parseError(string $message): self
    {
        return new self(sprintf('HTTP parse error: %s', $message));
    }

    public static function payloadTooLarge(int $maxBytes): self
    {
        return new self(sprintf('Request body exceeds maximum size of %d bytes', $maxBytes));
    }

    public static function headersTooLarge(int $maxBytes): self
    {
        return new self(sprintf('Request headers exceed maximum size of %d bytes', $maxBytes));
    }

    public static function bindingRefused(string $host, int $port, string $reason): self
    {
        return new self(sprintf('Cannot bind to %s:%d — %s', $host, $port, $reason));
    }

    public static function extensionMissing(string $extension): self
    {
        return new self(sprintf(
            'Required extension "%s" is not loaded. Install or enable it in php.ini.',
            $extension,
        ));
    }

    public static function fatalError(string $message): self
    {
        return new self(sprintf('Fatal runtime error: %s', $message));
    }
}
