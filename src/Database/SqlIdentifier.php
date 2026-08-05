<?php

declare(strict_types=1);

namespace Pulsar\Database;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function preg_match;
use function sprintf;
use function strlen;

/**
 * Validates and quotes SQL identifiers — table names, column names, index names.
 *
 * Identifiers cannot be bound as parameters, so anything that reaches the SQL text as an
 * identifier has to be proven safe rather than escaped. This class is that proof: the
 * pattern admits only a leading letter or underscore followed by letters, digits and
 * underscores, which leaves no representation for a quote character, a comment
 * introducer, or a statement separator.
 *
 * ## Quoting is per engine, and there is no portable default
 *
 * MySQL delimits with backticks; PostgreSQL and SQLite with double quotes, which is what
 * the standard says. Backticks are not an alternative spelling in PostgreSQL — they are a
 * syntax error — so a `quote()` that did not know the engine could only be right on one
 * of the three. That is why {@see quote()} requires a {@see Driver}.
 *
 * ## Length is bounded by the strictest engine, deliberately
 *
 * MySQL allows 64 characters, PostgreSQL 63, SQLite effectively none. An identifier this
 * framework accepts must work on every engine it claims to support, so the bound is 63:
 * accepting a 64-character name would produce a schema that migrates on MySQL and fails
 * on PostgreSQL, which is a worse outcome than refusing it here.
 *
 * @api
 */
#[Api(since: '1.0.0')]
final class SqlIdentifier
{
    /**
     * PostgreSQL truncates at NAMEDATALEN - 1 = 63 bytes; MySQL rejects beyond 64.
     */
    public const int MAX_LENGTH = 63;

    private const string PATTERN = '/\A[a-zA-Z_][a-zA-Z0-9_]*\z/';

    /**
     * Validate that a string is a safe SQL identifier on every supported engine.
     *
     * @throws InvalidArgumentException If the identifier contains invalid characters
     *                                  or exceeds what the strictest engine accepts.
     */
    public static function validate(string $identifier): string
    {
        if (preg_match(self::PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid SQL identifier: "%s". Only letters, digits and underscores are allowed, '
                . 'and the first character may not be a digit.',
                $identifier,
            ));
        }

        if (strlen($identifier) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'SQL identifier "%s" is %d characters; the portable limit is %d, which is what '
                . 'PostgreSQL accepts. A longer name would migrate on MySQL and fail there.',
                $identifier,
                strlen($identifier),
                self::MAX_LENGTH,
            ));
        }

        return $identifier;
    }

    /**
     * Validate an identifier and delimit it the way the given engine expects.
     *
     * @throws InvalidArgumentException If the identifier is not valid on every engine.
     */
    public static function quote(string $identifier, Driver $driver): string
    {
        self::validate($identifier);

        $delimiter = self::delimiter($driver);

        return $delimiter . $identifier . $delimiter;
    }

    /**
     * The delimiter this engine uses around an identifier.
     *
     * Exposed because compilers that build long statements quote many identifiers in one
     * pass and would otherwise re-derive this per name.
     */
    public static function delimiter(Driver $driver): string
    {
        return match ($driver) {
            Driver::MySQL => '`',
            // The SQL standard's delimiter. SQLite also tolerates backticks and square
            // brackets for compatibility with other engines; it is given the standard
            // form so that generated SQL reads the same on both non-MySQL engines.
            Driver::PostgreSQL, Driver::SQLite => '"',
        };
    }
}
