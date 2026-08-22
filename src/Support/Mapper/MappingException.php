<?php

declare(strict_types=1);

namespace Pulsar\Support\Mapper;

use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function get_debug_type;
use function is_scalar;
use function is_string;
use function sprintf;

/**
 * Exception thrown when object mapping fails.
 * @api
 */
#[Api(since: '1.0.0')]
final class MappingException extends RuntimeException
{
    public static function classNotFound(string $class, ?Throwable $previous = null): self
    {
        return new self("Target class '$class' does not exist", 0, $previous);
    }

    public static function noConstructor(string $class): self
    {
        return new self("Class '$class' has no constructor but data was provided");
    }

    public static function missingRequired(string $class, string $param): self
    {
        return new self("Missing required parameter '$param' for class '$class'");
    }

    public static function nullNotAllowed(string $class, string $param): self
    {
        return new self("Parameter '$param' of class '$class' does not accept null");
    }

    public static function typeMismatch(string $class, string $param, string $expected, mixed $actual): self
    {
        return new self(sprintf(
            "Parameter '%s' of class '%s' expects '%s', got '%s'",
            $param,
            $class,
            $expected,
            get_debug_type($actual),
        ));
    }

    public static function enumFailed(string $class, string $param, string $enumClass, mixed $value, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            "Cannot map value '%s' to enum '%s' for parameter '%s' of class '%s'",
            (is_string($value) ? $value : (is_scalar($value) ? (string) $value : get_debug_type($value))),
            $enumClass,
            $param,
            $class,
        ), 0, $previous);
    }

    public static function factoryFailed(string $class, Throwable $previous): self
    {
        return new self("Factory method fromArray() failed for class '$class': " . $previous->getMessage(), 0, $previous);
    }

    public static function constructionFailed(string $class, Throwable $previous): self
    {
        return new self("Failed to construct '$class': " . $previous->getMessage(), 0, $previous);
    }

    public static function invalidListItem(string $class, int $index): self
    {
        return new self("Item at index $index is not an array (mapping to '$class')");
    }
}
