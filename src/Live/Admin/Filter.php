<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;

/**
 * Base class for admin resource list filters.
 *
 * Filters restrict the list view by applying criteria to specific fields.
 */
#[Api(since: '1.0.0')]
abstract class Filter
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly string $type,
    ) {}

    /**
     * Apply this filter to a query parameters array.
     *
     * @param array<string, mixed> $query Current query parameters
     * @param mixed $value The filter value from the UI
     * @return array<string, mixed> Modified query parameters
     */
    abstract public function apply(array $query, mixed $value): array;
}
