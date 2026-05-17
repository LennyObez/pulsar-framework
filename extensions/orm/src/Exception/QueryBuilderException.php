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
