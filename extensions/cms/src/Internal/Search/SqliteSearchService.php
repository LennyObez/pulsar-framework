<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Search;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Search\DateRange;
use Pulsar\Extension\Cms\Search\SearchAnalytics;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Extension\Cms\Search\SearchResult;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function array_column;
use function array_slice;
use function bin2hex;
use function exp;
use function implode;
use function max;
use function microtime;
use function min;
use function round;
use function sodium_crypto_generichash;
use function str_replace;
use function strtolower;
use function trim;
use function usort;

/**
 * SQLite FTS5 full-text search with composite ranking (bm25 + recency + taxonomy boost).
 */
/**
 * @psalm-api Returned by SearchServiceFactory::create() when the active driver
 *            is SQLite; not instantiated by name outside the factory.
 */
#[Internal(reason: 'Use SearchServiceInterface for public API')]
final readonly class SqliteSearchService implements SearchServiceInterface
{
    private const string SQL_SEARCH = <<<'SQL'
        SELECT c.*,
            bm25(cms_content_fts) AS fts_rank
        FROM cms_content_fts
        INNER JOIN cms_content_translations ct ON cms_content_fts.rowid = ct.rowid
        INNER JOIN cms_contents c ON c.id = ct.content_id
        WHERE cms_content_fts MATCH :query
            AND ct.locale = :locale
            AND c.status = 'published'
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_COUNT = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_content_fts
        INNER JOIN cms_content_translations ct ON cms_content_fts.rowid = ct.rowid
        INNER JOIN cms_contents c ON c.id = ct.content_id
        WHERE cms_content_fts MATCH :query
            AND ct.locale = :locale
            AND c.status = 'published'
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_SUGGEST = <<<'SQL'
        SELECT DISTINCT ct.title
        FROM cms_content_translations ct
        INNER JOIN cms_contents c ON c.id = ct.content_id
        WHERE ct.locale = :locale
            AND c.status = 'published'
            AND c.deleted_at IS NULL
            AND lower(ct.title) LIKE :prefix ESCAPE '\'
        ORDER BY ct.title
        LIMIT :limit
        SQL;

    private const string SQL_TAXONOMY_MATCH_COUNTS_PREFIX = <<<'SQL'
        SELECT ctt.content_id, COUNT(*) AS match_count
        FROM cms_content_taxonomy_terms ctt
        INNER JOIN cms_taxonomy_term_translations ttt ON ttt.term_id = ctt.term_id
        INNER JOIN cms_taxonomy_terms tt ON tt.id = ctt.term_id
        INNER JOIN cms_taxonomies t ON t.id = tt.taxonomy_id
        WHERE ttt.locale = :locale
            AND ctt.content_id IN (
        SQL;

    private const string SQL_TAXONOMY_MATCH_COUNTS_SUFFIX = <<<'SQL'
        )
        GROUP BY ctt.content_id
        SQL;

    /** Recency decay constant: half-life at ~62 days (90 / ln(2)). */
    private const float RECENCY_DECAY_DAYS = 90.0;

    /** Maximum recency boost factor. */
    private const float RECENCY_BOOST_BASE = 0.5;

    /** Taxonomy term match boost factor per term. */
    private const float TAXONOMY_BOOST_PER_TERM = 0.1;

    public function __construct(
        private ConnectionInterface $connection,
        private SearchAnalyticsRepositoryInterface $analyticsRepository,
        private ?string $tenantId,
    ) {}

    public function search(
        string $query,
        string $locale,
        ?string $contentType = null,
        ?array $taxonomyFilters = null,
        int $page = 1,
        int $perPage = 20,
    ): SearchResult {
        $startTime = microtime(true);
        $query = trim($query);

        if ($query === '') {
            return new SearchResult(
                items: [],
                total: 0,
                query: $query,
                suggestions: [],
                tookMs: 0.0,
            );
        }

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $bindings = [
            'query' => $query,
            'locale' => $locale,
        ];

        $searchSql = self::SQL_SEARCH;
        $countSql = self::SQL_COUNT;

        if ($contentType !== null) {
            $typeFilter = ' AND c.content_type = :content_type';
            $searchSql .= $typeFilter;
            $countSql .= $typeFilter;
            $bindings['content_type'] = $contentType;
        }

        // Count total matches
        $total = $this->connection->query($countSql, $bindings)->first()?->getInt('total') ?? 0;

        // Fetch a window for PHP-side re-ranking
        $fetchLimit = min($offset + $perPage * 3, max($total, $perPage));
        $searchSql .= ' ORDER BY fts_rank LIMIT :fetch_limit';
        $bindings['fetch_limit'] = $fetchLimit;

        $result = $this->connection->query($searchSql, $bindings);

        if ($result->isEmpty()) {
            $this->recordSearch($query, $locale, 0);

            return new SearchResult(
                items: [],
                total: 0,
                query: $query,
                suggestions: $this->suggest($query, $locale),
                tookMs: round((microtime(true) - $startTime) * 1000.0, 2),
            );
        }

        // Hydrate content with FTS rank
        $ranked = [];
        $contentIds = [];

        foreach ($result->rows as $row) {
            $content = self::hydrateContent($row);
            // bm25() returns negative values: lower (more negative) = better match
            $ftsRank = -$row->getFloat('fts_rank');
            $contentIds[] = $content->id;
            $ranked[] = ['content' => $content, 'fts_rank' => $ftsRank];
        }

        // Load taxonomy match counts for boosting
        $taxonomyCounts = $this->loadTaxonomyMatchCounts($contentIds, $locale);

        // Apply composite scoring
        $now = new DateTimeImmutable();

        foreach ($ranked as &$entry) {
            /** @var Content $content */
            $content = $entry['content'];

            $daysSinceUpdate = max(0, (int) $now->diff($content->updatedAt)->days);
            $recencyBoost = 1.0 + self::RECENCY_BOOST_BASE * exp((float) (-$daysSinceUpdate) / self::RECENCY_DECAY_DAYS);
            $taxonomyBoost = 1.0 + self::TAXONOMY_BOOST_PER_TERM * (float) ($taxonomyCounts[$content->id] ?? 0);

            $entry['final_score'] = $entry['fts_rank'] * $recencyBoost * $taxonomyBoost;
        }

        unset($entry);

        // Re-sort by final composite score (higher is better)
        usort($ranked, static fn(array $a, array $b): int => ($b['final_score'] ?? 0.0) <=> ($a['final_score'] ?? 0.0));

        // Apply pagination to re-ranked results
        $pageSlice = array_slice($ranked, $offset, $perPage);
        $items = array_column($pageSlice, 'content');

        $this->recordSearch($query, $locale, $total);

        $tookMs = round((microtime(true) - $startTime) * 1000.0, 2);

        return new SearchResult(
            items: $items,
            total: $total,
            query: $query,
            suggestions: $total === 0 ? $this->suggest($query, $locale) : [],
            tookMs: $tookMs,
        );
    }

    public function suggest(string $partialQuery, string $locale, int $limit = 5): array
    {
        $partialQuery = trim($partialQuery);

        if ($partialQuery === '') {
            return [];
        }

        // Escape LIKE special characters
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($partialQuery));
        $prefix = $escaped . '%';

        $result = $this->connection->query(self::SQL_SUGGEST, [
            'locale' => $locale,
            'prefix' => $prefix,
            'limit' => $limit,
        ]);

        return $result->map(static fn(Row $row): string => $row->getString('title'));
    }

    public function recordClick(string $queryHash, string $contentId): void
    {
        $this->analyticsRepository->recordClick($queryHash, $contentId);
    }

    public function getAnalytics(DateRange $range, ?string $tenantId = null): SearchAnalytics
    {
        $topQueries = $this->analyticsRepository->getTopQueries($range, $tenantId);
        $zeroResultQueries = $this->analyticsRepository->getZeroResultQueries($range, $tenantId);
        $clickThroughRates = $this->analyticsRepository->getClickThroughData($range, $tenantId);
        $totals = $this->analyticsRepository->getTotals($range, $tenantId);

        return new SearchAnalytics(
            topQueries: $topQueries,
            zeroResultQueries: $zeroResultQueries,
            clickThroughRates: $clickThroughRates,
            totalSearches: $totals['total_searches'],
            uniqueQueries: $totals['unique_queries'],
        );
    }

    /**
     * Load the count of taxonomy terms associated with each content item.
     *
     * @param list<string> $contentIds
     * @return array<string, int>
     */
    private function loadTaxonomyMatchCounts(array $contentIds, string $locale): array
    {
        if ($contentIds === []) {
            return [];
        }

        $bindings = ['locale' => $locale];
        $inParts = [];

        foreach ($contentIds as $i => $id) {
            $param = 'cid_' . $i;
            $inParts[] = ':' . $param;
            $bindings[$param] = $id;
        }

        $sql = self::SQL_TAXONOMY_MATCH_COUNTS_PREFIX
            . implode(',', $inParts)
            . self::SQL_TAXONOMY_MATCH_COUNTS_SUFFIX;

        $result = $this->connection->query($sql, $bindings);

        $counts = [];

        foreach ($result->rows as $row) {
            $counts[$row->getString('content_id')] = $row->getInt('match_count');
        }

        return $counts;
    }

    private function recordSearch(string $query, string $locale, int $resultCount): void
    {
        $queryHash = bin2hex(sodium_crypto_generichash($query));

        $this->analyticsRepository->record(
            id: UuidGenerator::v7(),
            tenantId: $this->tenantId,
            queryText: $query,
            queryHash: $queryHash,
            locale: $locale,
            resultCount: $resultCount,
        );
    }

    private static function hydrateContent(Row $row): Content
    {
        return new Content(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            contentType: ContentType::from($row->getString('content_type')),
            authorId: $row->getString('author_id'),
            status: PublishingStatus::from($row->getString('status')),
            scheduledPublishAt: self::toDateTime($row->getNullableString('scheduled_publish_at')),
            scheduledUnpublishAt: self::toDateTime($row->getNullableString('scheduled_unpublish_at')),
            publishedAt: self::toDateTime($row->getNullableString('published_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
            deletedAt: self::toDateTime($row->getNullableString('deleted_at')),
            template: $row->getNullableString('template'),
            parentId: $row->getNullableString('parent_id'),
            sortOrder: $row->getInt('sort_order'),
            commentPolicy: CommentPolicy::from($row->getString('comment_policy')),
            dataClassification: DataClassification::from($row->getString('data_classification')),
            version: $row->getInt('version'),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
