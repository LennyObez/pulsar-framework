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

    public function save(Content $content): void;

    public function delete(Content $content): void;

    /**
     * Find all descendant content items for cascading path recomputation.
     *
     * @return list<Content>
     */
    public function findDescendants(string $contentId): array;
}
