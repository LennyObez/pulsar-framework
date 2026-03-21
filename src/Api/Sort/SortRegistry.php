<?php

declare(strict_types=1);

namespace Pulsar\Api\Sort;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Exception\ApiException;

use function array_key_exists;
use function array_keys;

/**
 * Per-resource registry of allowed sort fields.
 *
 * Sorts must be explicitly registered. Unregistered fields are rejected
 * with 400 Bad Request.
 * @api
 */
#[Api(since: '1.0.0')]
final class SortRegistry
{
    /**
     * @var array<string, array<string, SortDefinition>> Resource type => field => definition
     */
    private array $definitions = [];

    /**
     * Register sort fields for a resource type.
     *
     * @param string $resourceType The resource type identifier
     * @param array<string, SortDefinition> $sorts Field name => definition
     */
    public function register(string $resourceType, array $sorts): void
    {
        $this->definitions[$resourceType] = $sorts;
    }

    /**
     * Get the sort definition for a specific resource field.
     *
     * @throws ApiException If the field is not registered for sorting
     */
    #[NoDiscard]
    public function get(string $resourceType, string $field): SortDefinition
    {
        if (!isset($this->definitions[$resourceType])) {
            throw ApiException::unknownResource($resourceType);
        }

        if (!array_key_exists($field, $this->definitions[$resourceType])) {
            throw ApiException::unknownSortField($field, $resourceType);
        }

        return $this->definitions[$resourceType][$field];
    }

    /**
     * Check if a sort field is registered for a resource type.
     */
    public function has(string $resourceType, string $field): bool
    {
        return isset($this->definitions[$resourceType][$field]);
    }

    /**
     * Get all registered sortable field names for a resource type.
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
