<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Search;

use Pulsar\Api\Api;

/**
 * Full-text search service for CMS content.
 *
 * Provides ranked full-text search with recency and taxonomy boosting,
 * typeahead suggestions, click tracking, and aggregated analytics.
 * Adapters exist for PostgreSQL (tsvector), SQLite (FTS5), and MySQL (FULLTEXT).
 */
#[Api(since: '1.0.0')]
interface SearchServiceInterface
{
    /**
     * Execute a full-text search against published content.
     *
     * @param string $query Raw user search query
     * @param string $locale BCP 47 locale code
     * @param string|null $contentType Filter by content type
     * @param array<string, list<string>>|null $taxonomyFilters Vocabulary slug => term slugs
     * @param int $page Page number (1-based)
     * @param int $perPage Results per page
     */
    public function search(
        string $query,
        string $locale,
        ?string $contentType = null,
        ?array $taxonomyFilters = null,
        int $page = 1,
        int $perPage = 20,
    ): SearchResult;

    /**
     * Get typeahead suggestions for a partial query.
     *
     * @param string $partialQuery Partial search input
     * @param string $locale BCP 47 locale code
     * @param int $limit Maximum suggestions to return
     * @return list<string>
     */
    public function suggest(string $partialQuery, string $locale, int $limit = 5): array;

    /**
     * Record a click on a search result for analytics.
     *
     * @param string $queryHash SHA-256 hash of the original query
     * @param string $contentId UUIDv7 of the clicked content
     */
    public function recordClick(string $queryHash, string $contentId): void;

    /**
     * Get aggregated search analytics for a date range.
     *
     * @param DateRange $range Date range to aggregate
     * @param string|null $tenantId Filter by tenant (null for all)
     */
    public function getAnalytics(DateRange $range, ?string $tenantId = null): SearchAnalytics;
}
