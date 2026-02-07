<?php

declare(strict_types=1);

namespace Pulsar\Api\Filter;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Exception\ApiException;

use function array_key_exists;
use function array_keys;

/**
 * Per-resource registry of allowed filters.
 *
 * Filters must be explicitly registered. Unregistered fields are rejected
 * with 400 Bad Request. This is a Finding E invariant: no open-ended query
 * parameters are permitted.
 */
#[Api(since: '1.0.0')]
final class FilterRegistry
{
    /**
     * @var array<string, array<string, FilterDefinition>> Resource type => field => definition
     */
    private array $definitions = [];

    /**
     * Register filters for a resource type.
     *
     * @param string $resourceType The resource type identifier
     * @param array<string, Filter> $filters Field name => filter builder
     */
    public function register(string $resourceType, array $filters): void
    {
        $built = [];

        foreach ($filters as $fieldName => $filter) {
            $built[$fieldName] = $filter->build($fieldName);
        }

        $this->definitions[$resourceType] = $built;
    }

    /**
     * Get the filter definition for a specific resource field.
     *
     * @throws ApiException If the resource type or field is not registered
     */
    #[NoDiscard]
    public function get(string $resourceType, string $field): FilterDefinition
    {
        if (!isset($this->definitions[$resourceType])) {
            throw ApiException::unknownResource($resourceType);
        }

        if (!array_key_exists($field, $this->definitions[$resourceType])) {
            throw ApiException::unknownFilterField($field, $resourceType);
        }

        return $this->definitions[$resourceType][$field];
    }

    /**
     * Check if a filter field is registered for a resource type.
     */
    public function has(string $resourceType, string $field): bool
    {
        return isset($this->definitions[$resourceType][$field]);
    }

    /**
     * Get all registered field names for a resource type.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function fields(string $resourceType): array
    {
        if (!isset($this->definitions[$resourceType])) {
            return [];
        }

        return array_keys($this->definitions[$resourceType]);
    }
}
