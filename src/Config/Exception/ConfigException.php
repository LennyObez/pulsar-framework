<?php

declare(strict_types=1);

namespace Pulsar\Config\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for configuration errors.
 */
#[Api]
final class ConfigException extends RuntimeException
{
    /**
     * Config file not found at path.
     */
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Configuration file not found: "%s"', $path));
    }

    /**
     * Config file did not return an array.
     */
    public static function invalidValue(string $path, string $reason): self
    {
        return new self(sprintf('Invalid configuration value in "%s": %s', $path, $reason));
    }

    /**
     * Required config key is missing.
     */
    public static function missingRequired(string $key, string $context): self
    {
        return new self(sprintf('Missing required configuration key "%s" in %s', $key, $context));
    }
}
