<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Execution;

use Pulsar\Api\Api;

/**
 * A parsed field from a GraphQL query AST.
 */
#[Api(since: '1.0.0')]
final readonly class ParsedField
{
    /**
     * @param string $name The field name in the schema
     * @param string|null $alias The alias if one was specified
     * @param array<string, string|int|float|bool|null> $arguments Parsed argument values
     * @param list<ParsedField> $selections Sub-field selections
     */
    public function __construct(
        public string $name,
        public ?string $alias = null,
        public array $arguments = [],
        public array $selections = [],
    ) {}

    /**
     * The response key: alias if set, otherwise the field name.
     */
    public function responseKey(): string
    {
        return $this->alias ?? $this->name;
    }
}
