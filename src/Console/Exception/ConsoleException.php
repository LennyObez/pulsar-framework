<?php

declare(strict_types=1);

namespace Pulsar\Console\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for console-related errors.
 */
#[Api]
class ConsoleException extends RuntimeException
{
    /**
     * Create exception for invalid input.
     */
    public static function invalidInput(string $message): self
    {
        return new self($message);
    }

    /**
     * Create exception for invalid option.
     */
    public static function invalidOption(string $name): self
    {
        return new self(sprintf('Invalid option: --%s', $name));
    }

    /**
     * Create exception for missing argument.
     */
    public static function missingArgument(string $name): self
    {
        return new self(sprintf('Missing required argument: %s', $name));
    }
}
