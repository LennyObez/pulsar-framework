<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

use function count;
use function sprintf;

/**
 * Qualified column reference (alias.column).
 *
 * Used in JOIN ON clauses where unqualified references are ambiguous.
 */
#[Api(since: '1.0.0')]
final readonly class QualifiedRef
{
    public function __construct(
        public string $alias,
        public string $column,
    ) {}

    /**
     * Parse a qualified reference from "alias.column" string.
     */
    #[NoDiscard]
    public static function parse(string $ref): self
    {
        $parts = explode('.', $ref, 2);
        if (count($parts) !== 2) {
            throw QueryBuilderException::unqualifiedJoinRef($ref);
        }

        IdentifierValidator::validate($parts[0]);
        IdentifierValidator::validate($parts[1]);

        return new self($parts[0], $parts[1]);
    }

    #[NoDiscard]
    public static function of(string $alias, string $column): self
    {
        IdentifierValidator::validate($alias);
        IdentifierValidator::validate($column);

        return new self($alias, $column);
    }

    public function toString(): string
    {
        return sprintf('%s.%s', $this->alias, $this->column);
    }
}
