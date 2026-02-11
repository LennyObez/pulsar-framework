<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Search;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Search\DateRange;
use Pulsar\Extension\Cms\Search\LocaleRegconfigMap;
use Pulsar\Extension\Cms\Search\SearchAnalytics;
use Pulsar\Extension\Cms\Search\SearchResult;

#[CoversClass(DateRange::class)]
#[CoversClass(LocaleRegconfigMap::class)]
#[CoversClass(SearchAnalytics::class)]
#[CoversClass(SearchResult::class)]
final class SearchEntitiesTest extends TestCase
{
    // -- DateRange -------------------------------------------------------------

    #[Test]
    public function dateRangeConstructor(): void
    {
        $from = new DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $to = new DateTimeImmutable('2025-03-31T23:59:59+00:00');

        $range = new DateRange(from: $from, to: $to);

        self::assertSame($from, $range->from);
        self::assertSame($to, $range->to);
    }

    // -- LocaleRegconfigMap ---------------------------------------------------

    #[Test]
    public function resolveKnownLocales(): void
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
    public function resolveLocaleWithRegionCode(): void
    {
        self::assertSame('english', LocaleRegconfigMap::resolve('en-US'));
        self::assertSame('french', LocaleRegconfigMap::resolve('fr-FR'));
        self::assertSame('german', LocaleRegconfigMap::resolve('de-AT'));
        self::assertSame('portuguese', LocaleRegconfigMap::resolve('pt-BR'));
    }

    #[Test]
    public function resolveUnknownLocaleFallsBackToSimple(): void
    {
        self::assertSame('simple', LocaleRegconfigMap::resolve('ja'));
        self::assertSame('simple', LocaleRegconfigMap::resolve('zh'));
        self::assertSame('simple', LocaleRegconfigMap::resolve('ko'));
        self::assertSame('simple', LocaleRegconfigMap::resolve('xx'));
    }

    // -- SearchAnalytics ------------------------------------------------------

    #[Test]
    public function searchAnalyticsConstructor(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [
                ['query_text' => 'php patterns', 'count' => 150, 'avg_results' => 12.5, 'ctr' => 0.45],
                ['query_text' => 'security best practices', 'count' => 98, 'avg_results' => 8.2, 'ctr' => 0.62],
            ],
            zeroResultQueries: [
                ['query_text' => 'quantum computing', 'count' => 5, 'last_searched' => '2025-03-07T10:00:00+00:00'],
            ],
            clickThroughRates: [
                ['query_text' => 'php patterns', 'clicks' => 68, 'searches' => 150, 'ctr' => 0.45],
            ],
            totalSearches: 2500,
            uniqueQueries: 420,
        );

        self::assertCount(2, $analytics->topQueries);
        self::assertCount(1, $analytics->zeroResultQueries);
        self::assertSame(2500, $analytics->totalSearches);
        self::assertSame(420, $analytics->uniqueQueries);
    }

    // -- SearchResult ---------------------------------------------------------

    #[Test]
    public function searchResultConstructor(): void
    {
        $result = new SearchResult(
            items: [],
            total: 42,
            query: 'php security',
            suggestions: ['php security best practices', 'php security audit'],
            tookMs: 15.3,
        );

        self::assertSame([], $result->items);
        self::assertSame(42, $result->total);
        self::assertSame('php security', $result->query);
        self::assertCount(2, $result->suggestions);
        self::assertSame(15.3, $result->tookMs);
    }

    #[Test]
    public function searchResultEmptyResults(): void
    {
        $result = new SearchResult(
            items: [],
            total: 0,
            query: 'nonexistent topic',
            suggestions: [],
            tookMs: 2.1,
        );

        self::assertSame(0, $result->total);
        self::assertSame([], $result->suggestions);
    }
}
