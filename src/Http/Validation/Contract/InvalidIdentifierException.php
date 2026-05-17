<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Contract;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when a table or column identifier fails validation.
 * @api
 */
#[Api(since: '1.0.0')]
final class InvalidIdentifierException extends InvalidArgumentException
{
    public static function forTable(string $value): self
    {
        return new self(sprintf('Invalid table name: "%s". Must be alphanumeric/underscore and not a SQL keyword.', $value));
    }

    public static function forColumn(string $value): self
    {
        return new self(sprintf('Invalid column name: "%s". Must be alphanumeric/underscore and not a SQL keyword.', $value));
    }
}
