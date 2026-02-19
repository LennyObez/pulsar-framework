<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Search\DateRange;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;

use function round;

/**
 * Persistence layer for search analytics data.
 */
#[Internal(reason: 'Raw-DB repository — use SearchServiceInterface for public API')]
final readonly class DbSearchAnalyticsRepository implements SearchAnalyticsRepositoryInterface
{
    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO cms_search_analytics (id, tenant_id, query_text, query_hash, locale, result_count, searched_at)
        VALUES (:id, :tenant_id, :query_text, :query_hash, :locale, :result_count, :searched_at)
        SQL;

    private const string SQL_RECORD_CLICK = <<<'SQL'
        UPDATE cms_search_analytics
        SET clicked_content_id = :content_id
        WHERE query_hash = :query_hash
            AND clicked_content_id IS NULL
        SQL;

    private const string SQL_TOP_QUERIES = <<<'SQL'
        SELECT
            query_text,
            COUNT(*) AS search_count,
            AVG(result_count) AS avg_results,
            COUNT(clicked_content_id)::float / NULLIF(COUNT(*), 0) AS ctr
        FROM cms_search_analytics
        WHERE searched_at >= :from AND searched_at <= :to
        SQL;

    private const string SQL_ZERO_RESULT_QUERIES = <<<'SQL'
        SELECT
            query_text,
            COUNT(*) AS search_count,
            MAX(searched_at) AS last_searched
        FROM cms_search_analytics
        WHERE result_count = 0
            AND searched_at >= :from AND searched_at <= :to
        SQL;

    private const string SQL_CLICK_THROUGH = <<<'SQL'
        SELECT
            query_text,
            COUNT(clicked_content_id) AS clicks,
            COUNT(*) AS searches,
            COUNT(clicked_content_id)::float / NULLIF(COUNT(*), 0) AS ctr
        FROM cms_search_analytics
        WHERE searched_at >= :from AND searched_at <= :to
        SQL;

    private const string SQL_TOTALS = <<<'SQL'
        SELECT
            COUNT(*) AS total_searches,
            COUNT(DISTINCT query_hash) AS unique_queries
        FROM cms_search_analytics
        WHERE searched_at >= :from AND searched_at <= :to
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function record(
        string $id,
        ?string $tenantId,
        string $queryText,
        string $queryHash,
        string $locale,
        int $resultCount,
    ): void {
        $this->connection->execute(self::SQL_INSERT, [
            'id' => $id,
            'tenant_id' => $tenantId,
            'query_text' => $queryText,
            'query_hash' => $queryHash,
            'locale' => $locale,
            'result_count' => $resultCount,
            'searched_at' => new DateTimeImmutable()->format('c'),
        ]);
    }

    public function recordClick(string $queryHash, string $contentId): void
    {
        $this->connection->execute(self::SQL_RECORD_CLICK, [
            'query_hash' => $queryHash,
            'content_id' => $contentId,
        ]);
    }

    /**
     * @return list<array{query_text: string, count: int, avg_results: float, ctr: float}>
     */
    public function getTopQueries(DateRange $range, ?string $tenantId, int $limit = 20): array
    {
        $sql = self::SQL_TOP_QUERIES;
        $bindings = [
            'from' => $range->from->format('c'),
            'to' => $range->to->format('c'),
        ];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $sql .= ' GROUP BY query_text ORDER BY search_count DESC LIMIT :limit';
        $bindings['limit'] = $limit;

        $result = $this->connection->query($sql, $bindings);

        return $result->map(static fn(Row $row): array => [
            'query_text' => $row->getString('query_text'),
            'count' => $row->getInt('search_count'),
            'avg_results' => round($row->getFloat('avg_results'), 2),
            'ctr' => round($row->getFloat('ctr'), 4),
        ]);
    }

    /**
     * @return list<array{query_text: string, count: int, last_searched: string}>
     */
    public function getZeroResultQueries(DateRange $range, ?string $tenantId, int $limit = 20): array
    {
        $sql = self::SQL_ZERO_RESULT_QUERIES;
        $bindings = [
            'from' => $range->from->format('c'),
            'to' => $range->to->format('c'),
        ];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $sql .= ' GROUP BY query_text ORDER BY search_count DESC LIMIT :limit';
        $bindings['limit'] = $limit;

        $result = $this->connection->query($sql, $bindings);

        return $result->map(static fn(Row $row): array => [
            'query_text' => $row->getString('query_text'),
            'count' => $row->getInt('search_count'),
            'last_searched' => $row->getString('last_searched'),
        ]);
    }

    /**
     * @return list<array{query_text: string, clicks: int, searches: int, ctr: float}>
     */
    public function getClickThroughData(DateRange $range, ?string $tenantId): array
    {
        $sql = self::SQL_CLICK_THROUGH;
        $bindings = [
            'from' => $range->from->format('c'),
            'to' => $range->to->format('c'),
        ];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $sql .= ' GROUP BY query_text HAVING COUNT(clicked_content_id) > 0 ORDER BY clicks DESC LIMIT 50';

        $result = $this->connection->query($sql, $bindings);

        return $result->map(static fn(Row $row): array => [
            'query_text' => $row->getString('query_text'),
            'clicks' => $row->getInt('clicks'),
            'searches' => $row->getInt('searches'),
            'ctr' => round($row->getFloat('ctr'), 4),
        ]);
    }

    /**
     * @return array{total_searches: int, unique_queries: int}
     */
    public function getTotals(DateRange $range, ?string $tenantId): array
    {
        $sql = self::SQL_TOTALS;
        $bindings = [
            'from' => $range->from->format('c'),
            'to' => $range->to->format('c'),
        ];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $row = $this->connection->query($sql, $bindings)->first();

        if ($row === null) {
            return ['total_searches' => 0, 'unique_queries' => 0];
        }

        return [
            'total_searches' => $row->getInt('total_searches'),
            'unique_queries' => $row->getInt('unique_queries'),
        ];
    }
}
