<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Analytics\Contracts\SearchAnalyticsServiceInterface;
use Pulsar\Extension\Analytics\Domain\SearchQuery;

use function round;

/**
 * Internal site search analytics: tracks what visitors search for on-site.
 *
 * Reads search events from the analytics_events table where event_name = 'search'.
 * The search query is stored in event_props as {"query": "...", "results": N}.
 */
#[Internal(reason: 'Search analytics service; use SearchAnalyticsServiceInterface')]
final readonly class SearchAnalyticsService implements SearchAnalyticsServiceInterface
{
    private const string SQL_TOP_QUERIES = <<<'SQL'
        SELECT
            JSON_EXTRACT(event_props, '$.query') AS query,
            COUNT(*) AS count,
            AVG(CAST(JSON_EXTRACT(event_props, '$.results') AS INTEGER)) AS avg_results
        FROM analytics_events
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
            AND event_name = 'search'
            AND JSON_EXTRACT(event_props, '$.query') IS NOT NULL
        GROUP BY JSON_EXTRACT(event_props, '$.query')
        ORDER BY count DESC
        LIMIT :limit
        SQL;

    private const string SQL_ZERO_RESULT_QUERIES = <<<'SQL'
        SELECT
            JSON_EXTRACT(event_props, '$.query') AS query,
            COUNT(*) AS count
        FROM analytics_events
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
            AND event_name = 'search'
            AND CAST(JSON_EXTRACT(event_props, '$.results') AS INTEGER) = 0
        GROUP BY JSON_EXTRACT(event_props, '$.query')
        ORDER BY count DESC
        LIMIT :limit
        SQL;

    private const string SQL_OVERVIEW = <<<'SQL'
        SELECT
            COUNT(*) AS total_searches,
            COUNT(DISTINCT JSON_EXTRACT(event_props, '$.query')) AS unique_queries,
            SUM(CASE WHEN CAST(JSON_EXTRACT(event_props, '$.results') AS INTEGER) = 0 THEN 1 ELSE 0 END) AS zero_results
        FROM analytics_events
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
            AND event_name = 'search'
        SQL;

    private const string SQL_SEARCH_CLICKS = <<<'SQL'
        SELECT COUNT(*) AS click_count
        FROM analytics_events
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
            AND event_name = 'search_click'
        SQL;

    private const string SQL_PER_QUERY_CTR = <<<'SQL'
        SELECT
            JSON_EXTRACT(event_props, '$.query') AS query,
            COUNT(*) AS clicks
        FROM analytics_events
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
            AND event_name = 'search_click'
            AND JSON_EXTRACT(event_props, '$.query') IS NOT NULL
        GROUP BY JSON_EXTRACT(event_props, '$.query')
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function getTopQueries(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 20,
    ): array {
        $result = $this->connection->query(self::SQL_TOP_QUERIES, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ]);

        // Load per-query click counts
        $clickResult = $this->connection->query(self::SQL_PER_QUERY_CTR, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        /** @var array<string, int> $clicksByQuery */
        $clicksByQuery = [];

        foreach ($clickResult->rows as $clickRow) {
            $q = $clickRow->getNullableString('query');

            if ($q !== null) {
                $clicksByQuery[trim($q, '"')] = $clickRow->getInt('clicks');
            }
        }

        $queries = [];

        foreach ($result->rows as $row) {
            $queryStr = $row->getNullableString('query');

            if ($queryStr === null) {
                continue;
            }

            // Strip JSON string quotes
            $queryStr = trim($queryStr, '"');
            $searchCount = $row->getInt('count');
            $clicks = $clicksByQuery[$queryStr] ?? 0;
            $ctr = $searchCount > 0
                ? round(($clicks / (float) $searchCount) * 100, 1)
                : 0.0;

            $queries[] = new SearchQuery(
                query: $queryStr,
                count: $searchCount,
                resultCount: $row->getNullableInt('avg_results') ?? 0,
                clickThroughRate: $ctr,
            );
        }

        return $queries;
    }

    #[Override]
    public function getZeroResultQueries(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 20,
    ): array {
        $result = $this->connection->query(self::SQL_ZERO_RESULT_QUERIES, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ]);

        $queries = [];

        foreach ($result->rows as $row) {
            $queryStr = $row->getNullableString('query');

            if ($queryStr === null) {
                continue;
            }

            $queryStr = trim($queryStr, '"');

            $queries[] = new SearchQuery(
                query: $queryStr,
                count: $row->getInt('count'),
                resultCount: 0,
            );
        }

        return $queries;
    }

    #[Override]
    public function getOverview(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $row = $this->connection->query(self::SQL_OVERVIEW, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->first();

        $totalSearches = $row?->getInt('total_searches') ?? 0;
        $zeroResults = $row?->getInt('zero_results') ?? 0;

        // Compute actual CTR from search_click events
        $clickRow = $this->connection->query(self::SQL_SEARCH_CLICKS, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->first();

        $totalClicks = $clickRow?->getInt('click_count') ?? 0;
        $avgCtr = $totalSearches > 0
            ? round(($totalClicks / (float) $totalSearches) * 100, 1)
            : 0.0;

        return [
            'total_searches' => $totalSearches,
            'unique_queries' => $row?->getInt('unique_queries') ?? 0,
            'zero_result_rate' => $totalSearches > 0 ? round(($zeroResults / (float) $totalSearches) * 100, 1) : 0.0,
            'avg_click_through_rate' => $avgCtr,
        ];
    }
}
