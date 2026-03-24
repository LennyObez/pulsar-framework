<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Search;

use Pulsar\Api\Api;

/**
 * Repository interface for search analytics persistence.
 *
 * @psalm-api Public binding contract; implemented by DbSearchAnalyticsRepository
 *            and consumed by SearchService implementations.
 */
#[Api(since: '1.0.0')]
interface SearchAnalyticsRepositoryInterface
{
    public function record(
        string $id,
        ?string $tenantId,
        string $queryText,
        string $queryHash,
        string $locale,
        int $resultCount,
    ): void;

    public function recordClick(string $queryHash, string $contentId): void;

    /**
     * @return list<array{query_text: string, count: int, avg_results: float, ctr: float}>
     */
    public function getTopQueries(DateRange $range, ?string $tenantId, int $limit = 20): array;

    /**
     * @return list<array{query_text: string, count: int, last_searched: string}>
     */
    public function getZeroResultQueries(DateRange $range, ?string $tenantId, int $limit = 20): array;

    /**
     * @return list<array{query_text: string, clicks: int, searches: int, ctr: float}>
     */
    public function getClickThroughData(DateRange $range, ?string $tenantId): array;

    /**
     * @return array{total_searches: int, unique_queries: int}
     */
    public function getTotals(DateRange $range, ?string $tenantId): array;
}
