<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Contracts;

use Pulsar\Api\Api;

/**
 * Read-side query interface for admin resources.
 * @api
 */
#[Api(since: '1.0.0')]
interface ResourceQueryInterface
{
    /**
     * List records with pagination, filtering, and sorting.
     *
     * @param array<string, mixed> $filters
     * @param array<string, string> $sort
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(
        DataResourceInterface $resource,
        array $filters = [],
        array $sort = [],
        int $page = 1,
        int $perPage = 25,
    ): array;

    /**
     * Get a single record by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(DataResourceInterface $resource, string $id): ?array;

    /**
     * Search across resource fields.
     *
     * @return list<array<string, mixed>>
     */
    public function search(DataResourceInterface $resource, string $query, int $limit = 10): array;

    /**
     * Count total records.
     *
     * @param array<string, mixed> $filters
     */
    public function count(DataResourceInterface $resource, array $filters = []): int;
}
