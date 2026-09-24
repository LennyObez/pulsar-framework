<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when query builder operations are invalid.
 * @api
 */
#[Api(since: '1.0.0')]
final class QueryBuilderException extends OrmException
{
    #[NoDiscard]
    public static function noTable(): self
    {
        return new self('No table specified for query');
    }

    #[NoDiscard]
    public static function invalidIdentifier(string $identifier): self
    {
        return new self(sprintf(
            'Invalid SQL identifier: "%s". Identifiers must match [a-zA-Z_][a-zA-Z0-9_]*',
            $identifier,
        ));
    }

    #[NoDiscard]
    public static function unqualifiedJoinRef(string $column): self
    {
        return new self(sprintf(
            'Join ON clause requires qualified references (alias.column), got: "%s"',
            $column,
        ));
    }

    #[NoDiscard]
    public static function aliasConflict(string $alias): self
    {
        return new self(sprintf(
            'Table alias "%s" is already in use',
            $alias,
        ));
    }

    /**
     * A named parameter was bound twice in one statement.
     *
     * Builder-generated names come from a single monotonic sequence shared with
     * every subquery, so they cannot repeat on their own. A repeat therefore
     * means a raw expression chose a name that some other clause already owns,
     * and merging the two binding maps would silently rebind that clause to a
     * value it never asked for — a query that reads correctly and returns the
     * wrong rows. The build is refused instead.
     */
    #[NoDiscard]
    public static function duplicateBinding(string $name): self
    {
        return new self(sprintf(
            'Parameter ":%s" is already bound in this query. Choose a distinct name for the raw expression\'s binding; '
            . 'builder-generated placeholders are reserved.',
            $name,
        ));
    }

    /**
     * An aggregate was requested over a query that groups its rows.
     *
     * The aggregate statement is assembled separately from the SELECT and has
     * no GROUP BY or HAVING of its own. Running it anyway would answer a
     * different question from the one the query asks — a flat row count where
     * the caller grouped, or a count that ignores a HAVING the page applies —
     * and would leave the HAVING clause's parameters bound to a statement that
     * never mentions them.
     */
    #[NoDiscard]
    public static function aggregateOverGroupedQuery(): self
    {
        return new self(
            'Cannot aggregate a query that uses GROUP BY or HAVING: the aggregate statement carries neither, '
            . 'so its answer would not match the rows the query returns. Aggregate over the grouped result yourself, '
            . 'or drop the grouping from the query you aggregate.',
        );
    }

    #[NoDiscard]
    public static function invalidOperator(string $operator): self
    {
        return new self(sprintf(
            'Invalid SQL operator: "%s". Allowed operators: =, !=, <>, <, >, <=, >=, LIKE, NOT LIKE, IN, NOT IN, IS, IS NOT, BETWEEN',
            $operator,
        ));
    }

    #[NoDiscard]
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
