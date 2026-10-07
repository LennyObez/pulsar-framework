<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Search;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Search\DateRange;
use Pulsar\Extension\Cms\Search\LocaleRegconfigMap;
use Pulsar\Extension\Cms\Search\SearchResult;

#[CoversClass(DateRange::class)]
#[CoversClass(LocaleRegconfigMap::class)]
#[CoversClass(SearchResult::class)]
final class SearchValueObjectsTest extends TestCase
{
    // -- DateRange -------------------------------------------------------------

    #[Test]
    public function dateRangeConstructor(): void
    {
        $from = new DateTimeImmutable('2025-01-01');
        $to = new DateTimeImmutable('2025-12-31');

        $range = new DateRange(from: $from, to: $to);

        self::assertSame($from, $range->from);
        self::assertSame($to, $range->to);
    }

    // -- SearchResult ---------------------------------------------------------

    #[Test]
    public function searchResultConstructor(): void
    {
        $result = new SearchResult(
            items: [],
            total: 42,
            query: 'php security',
            suggestions: ['php safety', 'php auth'],
            tookMs: 12.5,
        );

        self::assertSame([], $result->items);
        self::assertSame(42, $result->total);
        self::assertSame('php security', $result->query);
        self::assertCount(2, $result->suggestions);
        self::assertSame(12.5, $result->tookMs);
    }

    #[Test]
    public function searchResultWithNoSuggestions(): void
    {
        $result = new SearchResult(
            items: [],
            total: 0,
            query: 'xyzzy',
            suggestions: [],
            tookMs: 0.5,
        );

        self::assertSame([], $result->suggestions);
        self::assertSame(0, $result->total);
    }

    // -- LocaleRegconfigMap ---------------------------------------------------

    #[Test]
    public function resolveKnownLocales(): void
    {
        self::assertSame('english', LocaleRegconfigMap::resolve('en'));
        self::assertSame('french', LocaleRegconfigMap::resolve('fr'));
        self::assertSame('german', LocaleRegconfigMap::resolve('de'));
        self::assertSame('spanish', LocaleRegconfigMap::resolve('es'));
        self::assertSame('italian', LocaleRegconfigMap::resolve('it'));
        self::assertSame('portuguese', LocaleRegconfigMap::resolve('pt'));
        self::assertSame('russian', LocaleRegconfigMap::resolve('ru'));
        self::assertSame('swedish', LocaleRegconfigMap::resolve('sv'));
        self::assertSame('dutch', LocaleRegconfigMap::resolve('nl'));
        self::assertSame('turkish', LocaleRegconfigMap::resolve('tr'));
        self::assertSame('arabic', LocaleRegconfigMap::resolve('ar'));
    }

    #[Test]
    public function resolveLocaleWithRegion(): void
    {
        self::assertSame('english', LocaleRegconfigMap::resolve('en-US'));
        self::assertSame('french', LocaleRegconfigMap::resolve('fr-CA'));
        self::assertSame('german', LocaleRegconfigMap::resolve('de-AT'));
    }

    #[Test]
    public function resolveUnknownLocaleFallsToSimple(): void
    {
        self::assertSame('simple', LocaleRegconfigMap::resolve('ja'));
        self::assertSame('simple', LocaleRegconfigMap::resolve('zh'));
        self::assertSame('simple', LocaleRegconfigMap::resolve('ko'));
    }
}
