<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for the Content aggregate root.
 *
 * @psalm-api Public binding contract; implemented by DbContentRepository and
 *            consumed by all content services, controllers, and user-land code.
 * @api
 */
#[Api(since: '1.0.0')]
interface ContentRepositoryInterface
{
    public function findById(string $id): ?Content;

    /**
     * Find a content item by its stable import identifier.
     *
     * Used for idempotent imports: if a record with this import_id exists,
     * the importer updates it instead of creating a duplicate.
     */
    public function findByImportId(string $importId): ?Content;

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

    /**
     * Find content items scheduled for publishing (status = 'scheduled' and scheduled_publish_at <= now).
     *
     * @return list<Content>
     */
    public function findScheduledForPublishing(DateTimeImmutable $now): array;

    /**
     * Find published content items scheduled for unpublishing (scheduled_unpublish_at <= now).
     *
     * @return list<Content>
     */
    public function findScheduledForUnpublishing(DateTimeImmutable $now): array;

    /**
     * Update the publishing status for multiple content items in a single query.
     *
     * Only affects non-deleted rows (deleted_at IS NULL).
     *
     * @param list<string> $ids UUIDv7 content IDs
     * @return int Number of affected rows
     */
    public function bulkUpdateStatus(array $ids, PublishingStatus $status, ?string $tenantId = null): int;

    /**
     * Soft-delete multiple content items in a single query.
     *
     * Sets deleted_at to the current timestamp. Only affects non-deleted rows.
     *
     * @param list<string> $ids UUIDv7 content IDs
     * @return int Number of affected rows
     */
    public function bulkDelete(array $ids, ?string $tenantId = null): int;
}
