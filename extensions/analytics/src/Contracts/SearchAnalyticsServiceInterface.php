<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\SearchQuery;

/**
 * Internal site search analytics.
 */
#[Api(since: '1.0.0')]
interface SearchAnalyticsServiceInterface
{
    /**
     * Get top search queries.
     *
     * @return list<SearchQuery>
     */
    public function getTopQueries(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 20,
    ): array;

    /**
     * Get queries that returned zero results.
     *
     * @return list<SearchQuery>
     */
    public function getZeroResultQueries(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 20,
    ): array;

    /**
     * Get search usage overview metrics.
     *
     * @return array{
     *     total_searches: int,
     *     unique_queries: int,
     *     zero_result_rate: float,
     *     avg_click_through_rate: float,
     * }
     */
    public function getOverview(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;
}
