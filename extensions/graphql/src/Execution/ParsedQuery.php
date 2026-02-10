<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Execution;

use Pulsar\Api\Api;

/**
 * A parsed GraphQL query consisting of top-level field selections.
 */
#[Api(since: '1.0.0')]
final readonly class ParsedQuery
{
    /**
     * @param list<ParsedField> $fields Top-level query fields
     * @param array<string, list<ParsedField>> $fragments Named fragment definitions
     */
    public function __construct(
        public array $fields,
        public array $fragments = [],
    ) {}
}
