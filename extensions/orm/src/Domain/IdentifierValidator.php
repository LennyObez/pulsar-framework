<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

use function preg_match;

/**
 * Validates SQL identifiers (table names, column names, aliases) at method-call time.
 */
#[Api(since: '1.0.0')]
final class IdentifierValidator
{
    private const string PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Validate a single identifier.
     *
     * @throws QueryBuilderException If the identifier is invalid.
     */
    public static function validate(string $identifier): void
    {
        if (preg_match(self::PATTERN, $identifier) !== 1) {
            throw QueryBuilderException::invalidIdentifier($identifier);
        }
    }

    /**
     * Validate an identifier that may be qualified (alias.column).
     *
     * @throws QueryBuilderException If any part is invalid.
     */
    public static function validateQualified(string $identifier): void
    {
        if (\str_contains($identifier, '.')) {
            $parts = \explode('.', $identifier, 2);
            self::validate($parts[0]);
            self::validate($parts[1]);

            return;
        }

        self::validate($identifier);
    }

    /**
     * Check if a string is a valid identifier without throwing.
     */
    public static function isValid(string $identifier): bool
    {
        return preg_match(self::PATTERN, $identifier) === 1;
    }
}
