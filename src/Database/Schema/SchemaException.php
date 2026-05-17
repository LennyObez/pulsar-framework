<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Driver;
use RuntimeException;

use function sprintf;

/**
 * Exception for schema DDL operations.
 * @api
 */
#[Api(since: '1.0.0')]
final class SchemaException extends RuntimeException
{
    #[NoDiscard]
    public static function invalidIdentifier(string $type, string $name, string $reason): self
    {
        return new self(sprintf('Invalid %s identifier "%s": %s', $type, $name, $reason));
    }

    #[NoDiscard]
    public static function operationNotSupported(Driver $driver, string $operation): self
    {
        return new self(sprintf('Operation "%s" is not supported by the %s driver', $operation, $driver->value));
    }

    #[NoDiscard]
    public static function tableAlreadyExists(string $table): self
    {
        return new self(sprintf('Table "%s" already exists', $table));
    }

    #[NoDiscard]
    public static function tableNotFound(string $table): self
    {
        return new self(sprintf('Table "%s" does not exist', $table));
    }

    #[NoDiscard]
    public static function columnNotFound(string $table, string $column): self
    {
        return new self(sprintf('Column "%s" does not exist on table "%s"', $column, $table));
    }

    #[NoDiscard]
    public static function columnAlreadyExists(string $table, string $column): self
    {
        return new self(sprintf('Column "%s" already exists on table "%s"', $column, $table));
    }
}
