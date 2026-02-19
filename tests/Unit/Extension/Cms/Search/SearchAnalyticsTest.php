<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Search\SearchAnalytics;
use ReflectionClass;

#[CoversClass(SearchAnalytics::class)]
final class SearchAnalyticsTest extends TestCase
{
    // -- Construction with zero results -----------------------------------

    #[Test]
    public function test_zero_result_query_recorded_correctly(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [],
            zeroResultQueries: [
                ['query_text' => 'nonexistent', 'count' => 1, 'last_searched' => '2026-02-19'],
            ],
            clickThroughRates: [],
            totalSearches: 1,
            uniqueQueries: 1,
        );

        self::assertCount(1, $analytics->zeroResultQueries);
        self::assertSame('nonexistent', $analytics->zeroResultQueries[0]['query_text']);
        self::assertSame(1, $analytics->zeroResultQueries[0]['count']);
    }

    // -- Click-through recording ------------------------------------------

    #[Test]
    public function test_click_through_data_stored(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [],
            zeroResultQueries: [],
            clickThroughRates: [
                ['query_text' => 'pulsar framework', 'clicks' => 5, 'searches' => 20, 'ctr' => 0.25],
            ],
            totalSearches: 20,
            uniqueQueries: 10,
        );

        self::assertCount(1, $analytics->clickThroughRates);
        self::assertSame(5, $analytics->clickThroughRates[0]['clicks']);
        self::assertSame(20, $analytics->clickThroughRates[0]['searches']);
        self::assertEqualsWithDelta(0.25, $analytics->clickThroughRates[0]['ctr'], 0.001);
    }

    // -- Query hash consistency -------------------------------------------

    #[Test]
    public function test_query_hash_computed_consistently(): void
    {
        $hash1 = sodium_crypto_generichash('test query');
        $hash2 = sodium_crypto_generichash('test query');

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function test_different_queries_produce_different_hashes(): void
    {
        $hash1 = sodium_crypto_generichash('query one');
        $hash2 = sodium_crypto_generichash('query two');

        self::assertNotSame($hash1, $hash2);
    }

    // -- Popular query aggregation ----------------------------------------

    #[Test]
    public function test_popular_queries_ordered_by_count(): void
    {
        $topQueries = [
            ['query_text' => 'most popular', 'count' => 100, 'avg_results' => 15.5, 'ctr' => 0.3],
            ['query_text' => 'second popular', 'count' => 50, 'avg_results' => 10.0, 'ctr' => 0.2],
            ['query_text' => 'least popular', 'count' => 5, 'avg_results' => 2.0, 'ctr' => 0.1],
        ];

        $analytics = new SearchAnalytics(
            topQueries: $topQueries,
            zeroResultQueries: [],
            clickThroughRates: [],
            totalSearches: 155,
            uniqueQueries: 3,
        );

        self::assertSame(100, $analytics->topQueries[0]['count']);
        self::assertSame(50, $analytics->topQueries[1]['count']);
        self::assertSame(5, $analytics->topQueries[2]['count']);
        self::assertGreaterThan(
            $analytics->topQueries[2]['count'],
            $analytics->topQueries[0]['count'],
        );
    }

    // -- Totals -----------------------------------------------------------

    #[Test]
    public function test_total_searches_and_unique_queries(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [],
            zeroResultQueries: [],
            clickThroughRates: [],
            totalSearches: 500,
            uniqueQueries: 120,
        );

        self::assertSame(500, $analytics->totalSearches);
        self::assertSame(120, $analytics->uniqueQueries);
    }

    // -- Readonly class ---------------------------------------------------

    #[Test]
    public function test_search_analytics_is_readonly(): void
    {
        $reflection = new ReflectionClass(SearchAnalytics::class);
        self::assertTrue($reflection->isReadOnly());
    }

    // -- Empty analytics --------------------------------------------------

    #[Test]
    public function test_empty_analytics(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [],
            zeroResultQueries: [],
            clickThroughRates: [],
            totalSearches: 0,
            uniqueQueries: 0,
        );

        self::assertSame(0, $analytics->totalSearches);
        self::assertSame(0, $analytics->uniqueQueries);
        self::assertSame([], $analytics->topQueries);
        self::assertSame([], $analytics->zeroResultQueries);
        self::assertSame([], $analytics->clickThroughRates);
    }
}
