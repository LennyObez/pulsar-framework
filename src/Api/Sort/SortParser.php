<?php

declare(strict_types=1);

namespace Pulsar\Api\Sort;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Exception\ApiException;

use function array_map;
use function explode;
use function in_array;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Parses raw sort query parameters into validated AST nodes.
 *
 * Expected format: `?sort=field1,-field2` (prefix `-` for descending).
 * Fields are validated against the SortRegistry.
 */
#[Api(since: '1.0.0')]
final readonly class SortParser
{
    public function __construct(
        private SortRegistry $registry,
    ) {}

    /**
     * Parse a sort parameter string.
     *
     * @param string $resourceType The resource type being sorted
     * @param string $sortParam Raw sort parameter (e.g. "name,-created_at")
     * @param list<string> $userRoles Current user's roles for authorization checks
     *
     * @throws ApiException On unknown field or unauthorized access
     *
     * @return list<SortExpression> Validated sort expressions
     */
    #[NoDiscard]
    public function parse(string $resourceType, string $sortParam, array $userRoles = []): array
    {
        if (trim($sortParam) === '') {
            return [];
        }

        $parts = array_map(trim(...), explode(',', $sortParam));
        $expressions = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            // Determine direction from prefix
            if (str_starts_with($part, '-')) {
                $field = substr($part, 1);
                $direction = SortDirection::Descending;
            } else {
                $field = $part;
                $direction = SortDirection::Ascending;
            }

            // Validate field is registered
            $definition = $this->registry->get($resourceType, $field);

            // Authorization check
            if ($definition->requiresAuthorization()) {
                if ($definition->guard !== null && !in_array($definition->guard, $userRoles, true)) {
                    throw ApiException::unauthorizedSort($field, $definition->guard);
                }
            }

            $expressions[] = SortExpression::create($definition->column, $direction);
        }

        return $expressions;
    }
}
