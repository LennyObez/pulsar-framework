<?php

declare(strict_types=1);

namespace Pulsar\Database;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function preg_match;
use function sprintf;

/**
 * Validates and quotes SQL identifiers to prevent SQL injection
 * via table names, column names, and other schema identifiers.
 */
#[Api(since: '1.0.0')]
final class SqlIdentifier
{
    private const string PATTERN = '/\A[a-zA-Z_][a-zA-Z0-9_]{0,127}\z/';

    /**
     * Validate that a string is a safe SQL identifier.
     *
     * @throws InvalidArgumentException If the identifier contains invalid characters
     */
    public static function validate(string $identifier): string
    {
        if (preg_match(self::PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid SQL identifier: "%s". Only alphanumeric characters and underscores are allowed.',
                $identifier,
            ));
        }

        return $identifier;
    }

    /**
     * Validate and backtick-quote a SQL identifier.
     *
     * @throws InvalidArgumentException If the identifier contains invalid characters
     */
    public static function quote(string $identifier): string
    {
        self::validate($identifier);

        return "`{$identifier}`";
    }
}
