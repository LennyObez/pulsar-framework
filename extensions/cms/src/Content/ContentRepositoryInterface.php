<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for the Content aggregate root.
 */
#[Api(since: '1.0.0')]
interface ContentRepositoryInterface
{
    public function findById(string $id): ?Content;

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?Content;

    /**
     * @return PaginationResult<Content>
     */
    public function findPublished(
        string $locale,
        ?string $contentType = null,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult;

    /**
     * Find multiple content items by their IDs in a single query.
     *
     * @param list<string> $ids UUIDv7 content IDs
     * @return array<string, Content> Keyed by content ID
     */
    public function findByIds(array $ids): array;

    /**
     * Find the ancestor chain for a content item by walking parent_id
     * references using a single recursive CTE query.
     *
     * Returns ancestors ordered from the immediate parent to the root.
     * The content item itself is NOT included in the result.
     *
     * @param string $contentId UUIDv7
     * @param int $maxDepth Maximum ancestor levels to traverse
     * @return list<Content> Ancestors from nearest parent to root
     */
    public function findAncestors(string $contentId, int $maxDepth = 20): array;

    public function save(Content $content): void;

    public function delete(Content $content): void;

    /**
     * Find all descendant content items for cascading path recomputation.
     *
     * @return list<Content>
     */
    public function findDescendants(string $contentId): array;
}
