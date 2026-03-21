<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Search;

use Pulsar\Api\Api;

/**
 * Aggregated search analytics report.
 *
 * @psalm-api Public DTO returned from SearchAnalyticsRepositoryInterface;
 *            consumed by analytics templates.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SearchAnalytics
{
    /**
     * @param list<array{query_text: string, count: int, avg_results: float, ctr: float}> $topQueries
     * @param list<array{query_text: string, count: int, last_searched: string}> $zeroResultQueries
     * @param list<array{query_text: string, clicks: int, searches: int, ctr: float}> $clickThroughRates
     * @param int $totalSearches
     * @param int $uniqueQueries
     */
    public function __construct(
        public array $topQueries,
        public array $zeroResultQueries,
        public array $clickThroughRates,
        public int $totalSearches,
        public int $uniqueQueries,
    ) {}
}
