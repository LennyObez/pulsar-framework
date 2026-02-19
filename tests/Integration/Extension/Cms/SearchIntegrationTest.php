<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Internal\Search\SearchService;
use Pulsar\Extension\Cms\Search\DateRange;
use Pulsar\Extension\Cms\Search\LocaleRegconfigMap;
use Pulsar\Extension\Cms\Search\SearchAnalytics;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Extension\Cms\Search\SearchResult;
use Pulsar\Extension\Cms\Search\SearchVectorComputer;
use RuntimeException;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function str_contains;
use function strtolower;

#[CoversClass(SearchService::class)]
#[CoversClass(SearchResult::class)]
#[CoversClass(SearchAnalytics::class)]
#[CoversClass(LocaleRegconfigMap::class)]
#[CoversClass(SearchVectorComputer::class)]
final class SearchIntegrationTest extends TestCase
{
    private InMemorySearchConnection $connection;
    private SearchService $searchService;
    private InMemoryAnalyticsRepository $analyticsRepo;

    protected function setUp(): void
    {
        $this->connection = new InMemorySearchConnection();
        $this->analyticsRepo = new InMemoryAnalyticsRepository();
        $this->searchService = new SearchService(
            $this->connection,
            $this->analyticsRepo,
            null,
        );
    }

    #[Test]
    public function test_search_returns_matching_content_ranked_by_relevance(): void
    {
        $this->connection->addContent(
            id: 'content-001',
            title: 'Introduction to PHP',
            bodyPlaintext: 'PHP is a popular programming language for web development.',
            locale: 'en',
            updatedAt: new DateTimeImmutable('-1 day'),
        );
        $this->connection->addContent(
            id: 'content-002',
            title: 'Advanced PHP Techniques',
            bodyPlaintext: 'Learn advanced PHP patterns and best practices.',
            locale: 'en',
            updatedAt: new DateTimeImmutable('-2 days'),
        );
        $this->connection->addContent(
            id: 'content-003',
            title: 'Getting Started with JavaScript',
            bodyPlaintext: 'JavaScript is a versatile scripting language.',
            locale: 'en',
            updatedAt: new DateTimeImmutable('-3 days'),
        );

        $result = $this->searchService->search('PHP', 'en');

        self::assertInstanceOf(SearchResult::class, $result);
        self::assertSame('PHP', $result->query);
        self::assertGreaterThanOrEqual(2, $result->total);
        self::assertCount(2, $result->items);

        // PHP content should be returned, JavaScript content should not
        $ids = array_map(static fn($c) => $c->id, $result->items);
        self::assertContains('content-001', $ids);
        self::assertContains('content-002', $ids);
        self::assertNotContains('content-003', $ids);
    }

    #[Test]
    public function test_search_with_french_locale_uses_french_stemming(): void
    {
        self::assertSame('french', LocaleRegconfigMap::resolve('fr'));
        self::assertSame('french', LocaleRegconfigMap::resolve('fr-FR'));

        $this->connection->addContent(
            id: 'content-fr-001',
            title: 'Introduction au PHP',
            bodyPlaintext: 'PHP est un langage de programmation populaire.',
            locale: 'fr',
            updatedAt: new DateTimeImmutable('-1 day'),
        );

        $result = $this->searchService->search('programmation', 'fr');

        self::assertGreaterThanOrEqual(1, $result->total);
        self::assertSame('programmation', $result->query);
    }

    #[Test]
    public function test_search_with_empty_query_returns_empty_result(): void
    {
        $result = $this->searchService->search('', 'en');

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
        self::assertSame('', $result->query);
        self::assertSame(0.0, $result->tookMs);
    }

    #[Test]
    public function test_search_with_whitespace_only_query_returns_empty_result(): void
    {
        $result = $this->searchService->search('   ', 'en');

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    #[Test]
    public function test_zero_results_recorded_in_analytics(): void
    {
        $result = $this->searchService->search('xyznonexistent', 'en');

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);

        // Analytics should have been recorded
        $records = $this->analyticsRepo->getRecords();
        self::assertCount(1, $records);
        self::assertSame('xyznonexistent', $records[0]['query_text']);
        self::assertSame(0, $records[0]['result_count']);
    }

    #[Test]
    public function test_successful_search_recorded_in_analytics(): void
    {
        $this->connection->addContent(
            id: 'content-010',
            title: 'Testing Analytics',
            bodyPlaintext: 'Testing that analytics are recorded.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
        );

        $this->searchService->search('Testing', 'en');

        $records = $this->analyticsRepo->getRecords();
        self::assertCount(1, $records);
        self::assertSame('Testing', $records[0]['query_text']);
        self::assertGreaterThan(0, $records[0]['result_count']);
    }

    #[Test]
    public function test_click_through_recording_updates_analytics(): void
    {
        $queryHash = 'abc123hash';
        $contentId = 'content-clicked';

        $this->searchService->recordClick($queryHash, $contentId);

        $clicks = $this->analyticsRepo->getClicks();
        self::assertCount(1, $clicks);
        self::assertSame($queryHash, $clicks[0]['query_hash']);
        self::assertSame($contentId, $clicks[0]['content_id']);
    }

    #[Test]
    public function test_suggestions_return_matching_titles(): void
    {
        $this->connection->addContent(
            id: 'content-020',
            title: 'Introduction to PHP',
            bodyPlaintext: 'PHP basics.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
        );
        $this->connection->addContent(
            id: 'content-021',
            title: 'Installing PHP',
            bodyPlaintext: 'Install PHP.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
        );
        $this->connection->addContent(
            id: 'content-022',
            title: 'JavaScript Guide',
            bodyPlaintext: 'JS guide.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
        );

        $suggestions = $this->searchService->suggest('Int', 'en');

        self::assertNotEmpty($suggestions);

        foreach ($suggestions as $suggestion) {
            self::assertStringStartsWith('Int', $suggestion);
        }
    }

    #[Test]
    public function test_suggestions_with_empty_query_returns_empty(): void
    {
        $suggestions = $this->searchService->suggest('', 'en');
        self::assertSame([], $suggestions);
    }

    #[Test]
    public function test_recency_boost_newer_content_ranks_higher(): void
    {
        $this->connection->addContent(
            id: 'content-old',
            title: 'PHP Guide Old',
            bodyPlaintext: 'PHP programming guide.',
            locale: 'en',
            updatedAt: new DateTimeImmutable('-365 days'),
            tsRank: 0.5,
        );
        $this->connection->addContent(
            id: 'content-new',
            title: 'PHP Guide New',
            bodyPlaintext: 'PHP programming guide.',
            locale: 'en',
            updatedAt: new DateTimeImmutable('-1 day'),
            tsRank: 0.5,
        );

        $result = $this->searchService->search('PHP', 'en');

        self::assertGreaterThanOrEqual(2, count($result->items));

        // With equal ts_rank, the newer content should rank higher due to recency boost
        self::assertSame('content-new', $result->items[0]->id);
        self::assertSame('content-old', $result->items[1]->id);
    }

    #[Test]
    public function test_taxonomy_boost_content_with_matching_terms_ranks_higher(): void
    {
        $this->connection->addContent(
            id: 'content-no-tax',
            title: 'PHP Basics',
            bodyPlaintext: 'PHP programming basics.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
            tsRank: 0.5,
            taxonomyTermCount: 0,
        );
        $this->connection->addContent(
            id: 'content-with-tax',
            title: 'PHP Advanced',
            bodyPlaintext: 'PHP programming advanced.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
            tsRank: 0.5,
            taxonomyTermCount: 5,
        );

        $result = $this->searchService->search('PHP', 'en');

        self::assertGreaterThanOrEqual(2, count($result->items));

        // Content with taxonomy terms should rank higher due to taxonomy boost
        self::assertSame('content-with-tax', $result->items[0]->id);
        self::assertSame('content-no-tax', $result->items[1]->id);
    }

    #[Test]
    public function test_content_type_filter_restricts_results(): void
    {
        $this->connection->addContent(
            id: 'content-article',
            title: 'PHP Article',
            bodyPlaintext: 'PHP article content.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
            contentType: 'article',
        );
        $this->connection->addContent(
            id: 'content-page',
            title: 'PHP Page',
            bodyPlaintext: 'PHP page content.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
            contentType: 'page',
        );

        $result = $this->searchService->search('PHP', 'en', contentType: 'article');

        $ids = array_map(static fn($c) => $c->id, $result->items);
        self::assertContains('content-article', $ids);
        self::assertNotContains('content-page', $ids);
    }

    #[Test]
    public function test_search_result_includes_timing(): void
    {
        $this->connection->addContent(
            id: 'content-timing',
            title: 'Timing Test',
            bodyPlaintext: 'Test timing.',
            locale: 'en',
            updatedAt: new DateTimeImmutable(),
        );

        $result = $this->searchService->search('Timing', 'en');

        self::assertGreaterThanOrEqual(0.0, $result->tookMs);
    }

    #[Test]
    public function test_locale_regconfig_map_resolves_supported_locales(): void
    {
        self::assertSame('english', LocaleRegconfigMap::resolve('en'));
        self::assertSame('french', LocaleRegconfigMap::resolve('fr'));
        self::assertSame('german', LocaleRegconfigMap::resolve('de'));
        self::assertSame('dutch', LocaleRegconfigMap::resolve('nl'));
        self::assertSame('spanish', LocaleRegconfigMap::resolve('es'));
        self::assertSame('italian', LocaleRegconfigMap::resolve('it'));
        self::assertSame('portuguese', LocaleRegconfigMap::resolve('pt'));
        self::assertSame('russian', LocaleRegconfigMap::resolve('ru'));
        self::assertSame('swedish', LocaleRegconfigMap::resolve('sv'));
        self::assertSame('danish', LocaleRegconfigMap::resolve('da'));
        self::assertSame('finnish', LocaleRegconfigMap::resolve('fi'));
        self::assertSame('hungarian', LocaleRegconfigMap::resolve('hu'));
        self::assertSame('norwegian', LocaleRegconfigMap::resolve('no'));
        self::assertSame('romanian', LocaleRegconfigMap::resolve('ro'));
        self::assertSame('turkish', LocaleRegconfigMap::resolve('tr'));
        self::assertSame('arabic', LocaleRegconfigMap::resolve('ar'));
    }

    #[Test]
    public function test_locale_regconfig_map_falls_back_to_simple(): void
    {
        self::assertSame('simple', LocaleRegconfigMap::resolve('zh'));
        self::assertSame('simple', LocaleRegconfigMap::resolve('ja'));
        self::assertSame('simple', LocaleRegconfigMap::resolve('ko'));
    }

    #[Test]
    public function test_locale_regconfig_map_handles_subtags(): void
    {
        self::assertSame('english', LocaleRegconfigMap::resolve('en-US'));
        self::assertSame('french', LocaleRegconfigMap::resolve('fr-CA'));
        self::assertSame('portuguese', LocaleRegconfigMap::resolve('pt-BR'));
    }

    #[Test]
    public function test_analytics_aggregation_returns_complete_report(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        $analytics = $this->searchService->getAnalytics($range);

        self::assertInstanceOf(SearchAnalytics::class, $analytics);
        self::assertSame([], $analytics->topQueries);
        self::assertSame([], $analytics->zeroResultQueries);
        self::assertSame([], $analytics->clickThroughRates);
        self::assertGreaterThanOrEqual(0, $analytics->totalSearches);
        self::assertGreaterThanOrEqual(0, $analytics->uniqueQueries);
    }

    #[Test]
    public function test_pagination_respects_page_and_per_page(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->connection->addContent(
                id: "content-page-{$i}",
                title: "PHP Tutorial Part {$i}",
                bodyPlaintext: 'PHP tutorial content.',
                locale: 'en',
                updatedAt: new DateTimeImmutable("-{$i} days"),
            );
        }

        $result = $this->searchService->search('PHP', 'en', page: 1, perPage: 2);

        self::assertSame(5, $result->total);
        self::assertLessThanOrEqual(2, count($result->items));
    }
}

/**
 * In-memory connection that simulates PostgreSQL full-text search behavior.
 */
final class InMemorySearchConnection implements ConnectionInterface
{
    /** @var list<array<string, mixed>> */
    private array $contents = [];

    public function addContent(
        string $id,
        string $title,
        string $bodyPlaintext,
        string $locale,
        DateTimeImmutable $updatedAt,
        string $contentType = 'article',
        float $tsRank = 0.0,
        int $taxonomyTermCount = 0,
    ): void {
        $now = new DateTimeImmutable();

        $this->contents[] = [
            'id' => $id,
            'tenant_id' => null,
            'content_type' => $contentType,
            'author_id' => 'author-test',
            'status' => 'published',
            'scheduled_publish_at' => null,
            'scheduled_unpublish_at' => null,
            'published_at' => $now->format('c'),
            'created_at' => $now->format('c'),
            'updated_at' => $updatedAt->format('c'),
            'deleted_at' => null,
            'template' => null,
            'parent_id' => null,
            'sort_order' => 0,
            'comment_policy' => 'inherit',
            'data_classification' => 'public',
            'title' => $title,
            'body_plaintext' => $bodyPlaintext,
            'locale' => $locale,
            'ts_rank' => $tsRank > 0.0 ? $tsRank : null,
            '_taxonomy_term_count' => $taxonomyTermCount,
        ];
    }

    public function query(string $sql, array $bindings = []): Result
    {
        // Simulate tsvector search by keyword matching
        if (str_contains($sql, 'ts_rank_cd')) {
            return $this->simulateSearch($bindings);
        }

        if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'search_vector')) {
            return $this->simulateCount($bindings);
        }

        if (str_contains($sql, 'DISTINCT ct.title')) {
            return $this->simulateSuggest($bindings);
        }

        if (str_contains($sql, 'cms_content_taxonomy_terms')) {
            return $this->simulateTaxonomyCounts($bindings);
        }

        // Analytics queries — return empty by default
        return new Result([]);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return 0;
    }

    public function prepare(string $sql): \Pulsar\Database\Statement
    {
        throw new RuntimeException('Not implemented');
    }

    public function beginTransaction(): \Pulsar\Database\Transaction
    {
        throw new RuntimeException('Not implemented');
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }

    public function lastInsertId(): string
    {
        return '';
    }

    public function driver(): \Pulsar\Database\Driver
    {
        return \Pulsar\Database\Driver::PostgreSQL;
    }

    public function name(): string
    {
        return 'test';
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function disconnect(): void {}

    /**
     * @param array<string, mixed> $bindings
     */
    private function simulateSearch(array $bindings): Result
    {
        /** @var string $queryRaw */
        $queryRaw = $bindings['query'] ?? '';
        $query = strtolower($queryRaw);
        /** @var string $locale */
        $locale = $bindings['locale'] ?? 'en';
        /** @var string|null $contentType */
        $contentType = $bindings['content_type'] ?? null;
        /** @var int $limit */
        $limit = $bindings['fetch_limit'] ?? 20;

        $matches = array_filter(
            $this->contents,
            /** @param array<string, mixed> $c */
            static function (array $c) use ($query, $locale, $contentType): bool {
                if ($c['locale'] !== $locale) {
                    return false;
                }

                if ($contentType !== null && $c['content_type'] !== $contentType) {
                    return false;
                }

                /** @var string $title */
                $title = $c['title'] ?? '';
                /** @var string $body */
                $body = $c['body_plaintext'] ?? '';

                return str_contains(strtolower($title), $query)
                    || str_contains(strtolower($body), $query);
            },
        );

        $rows = [];
        $rank = 1.0;

        foreach (array_values($matches) as $match) {
            $rowData = $match;
            $rowData['ts_rank'] = $match['ts_rank'] ?? $rank;
            unset($rowData['_taxonomy_term_count']);
            $rows[] = new Row($rowData);
            $rank -= 0.1;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return new Result($rows);
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function simulateCount(array $bindings): Result
    {
        /** @var string $queryRaw */
        $queryRaw = $bindings['query'] ?? '';
        $query = strtolower($queryRaw);
        /** @var string $locale */
        $locale = $bindings['locale'] ?? 'en';
        /** @var string|null $contentType */
        $contentType = $bindings['content_type'] ?? null;

        $count = 0;

        foreach ($this->contents as $c) {
            if ($c['locale'] !== $locale) {
                continue;
            }

            if ($contentType !== null && $c['content_type'] !== $contentType) {
                continue;
            }

            /** @var string $title */
            $title = $c['title'] ?? '';
            /** @var string $body */
            $body = $c['body_plaintext'] ?? '';

            if (str_contains(strtolower($title), $query) || str_contains(strtolower($body), $query)) {
                $count++;
            }
        }

        return new Result([new Row(['total' => $count])]);
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function simulateSuggest(array $bindings): Result
    {
        /** @var string $prefixRaw */
        $prefixRaw = $bindings['prefix'] ?? '';
        $prefix = rtrim(strtolower($prefixRaw), '%');
        /** @var string $locale */
        $locale = $bindings['locale'] ?? 'en';
        /** @var int $limit */
        $limit = $bindings['limit'] ?? 5;

        $titles = [];

        foreach ($this->contents as $c) {
            if ($c['locale'] !== $locale) {
                continue;
            }

            /** @var string $title */
            $title = $c['title'] ?? '';

            if (str_starts_with(strtolower($title), $prefix)) {
                $titles[] = $title;
            }
        }

        $titles = array_unique($titles);
        sort($titles);
        $titles = array_slice($titles, 0, $limit);

        return new Result(array_map(
            static fn(string $t): Row => new Row(['title' => $t]),
            $titles,
        ));
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function simulateTaxonomyCounts(array $bindings): Result
    {
        $rows = [];

        foreach ($this->contents as $c) {
            if (($c['_taxonomy_term_count'] ?? 0) > 0) {
                $rows[] = new Row([
                    'content_id' => $c['id'],
                    'match_count' => $c['_taxonomy_term_count'],
                ]);
            }
        }

        return new Result($rows);
    }
}

/**
 * In-memory analytics repository for testing.
 */
final class InMemoryAnalyticsRepository implements SearchAnalyticsRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    private array $records = [];

    /** @var list<array{query_hash: string, content_id: string}> */
    private array $clicks = [];

    public function record(
        string $id,
        ?string $tenantId,
        string $queryText,
        string $queryHash,
        string $locale,
        int $resultCount,
    ): void {
        $this->records[] = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'query_text' => $queryText,
            'query_hash' => $queryHash,
            'locale' => $locale,
            'result_count' => $resultCount,
        ];
    }

    public function recordClick(string $queryHash, string $contentId): void
    {
        $this->clicks[] = ['query_hash' => $queryHash, 'content_id' => $contentId];
    }

    public function getTopQueries(DateRange $range, ?string $tenantId, int $limit = 20): array
    {
        return [];
    }

    public function getZeroResultQueries(DateRange $range, ?string $tenantId, int $limit = 20): array
    {
        return [];
    }

    public function getClickThroughData(DateRange $range, ?string $tenantId): array
    {
        return [];
    }

    public function getTotals(DateRange $range, ?string $tenantId): array
    {
        return ['total_searches' => count($this->records), 'unique_queries' => count($this->records)];
    }

    /** @return list<array<string, mixed>> */
    public function getRecords(): array
    {
        return $this->records;
    }

    /** @return list<array{query_hash: string, content_id: string}> */
    public function getClicks(): array
    {
        return $this->clicks;
    }
}
