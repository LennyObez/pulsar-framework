<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Adapter;

use InvalidArgumentException;
use Pulsar\Api\Internal;

use function preg_match;
use function sprintf;

/**
 * Validates and quotes SQL identifiers to prevent SQL injection
 * via table names, column names, and other schema identifiers.
 */
#[Internal]
trait SqlIdentifierValidator
{
    /**
     * Validate that a string is a safe SQL identifier and return it backtick-quoted.
     *
     * Accepts only alphanumeric characters and underscores, starting with a letter
     * or underscore, up to 128 characters.
     *
     * @throws InvalidArgumentException If the identifier contains invalid characters
     */
    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,127}\z/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid SQL identifier: "%s". Only alphanumeric characters and underscores are allowed.',
                $identifier,
            ));
        }

        return "`$identifier`";
    }
}
