<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Query\EventQueryResult;

#[CoversClass(EventQueryResult::class)]
final class EventQueryResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $items = [
            ['event_id' => 'event-1', 'event_type' => 'http.request'],
            ['event_id' => 'event-2', 'event_type' => 'db.query'],
        ];

        $result = new EventQueryResult(
            items: $items,
            total: 100,
            limit: 25,
            offset: 50,
        );

        self::assertSame($items, $result->items);
        self::assertSame(100, $result->total);
        self::assertSame(25, $result->limit);
        self::assertSame(50, $result->offset);
    }

    #[Test]
    public function hasMoreReturnsTrueWhenMoreItemsExist(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 25,
            offset: 0,
        );

        self::assertTrue($result->hasMore());
    }

    #[Test]
    public function hasMoreReturnsFalseWhenOnLastPage(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 25,
            offset: 75,
        );

        self::assertFalse($result->hasMore());
    }

    #[Test]
    public function hasMoreReturnsFalseWhenExactlyAtEnd(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 50,
            offset: 50,
        );

        self::assertFalse($result->hasMore());
    }

    #[Test]
    public function hasMoreReturnsFalseWhenOffsetBeyondTotal(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 50,
            limit: 25,
            offset: 75,
        );

        self::assertFalse($result->hasMore());
    }

    #[Test]
    public function hasMoreReturnsFalseWhenTotalIsZero(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 0,
            limit: 25,
            offset: 0,
        );

        self::assertFalse($result->hasMore());
    }

    #[Test]
    public function pageReturnsCorrectPageNumber(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 25,
            offset: 50,
        );

        self::assertSame(3, $result->page());
    }

    #[Test]
    public function pageReturnsOneForFirstPage(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 25,
            offset: 0,
        );

        self::assertSame(1, $result->page());
    }

    #[Test]
    public function pageReturnsOneWhenLimitIsZero(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 0,
            offset: 50,
        );

        self::assertSame(1, $result->page());
    }

    #[Test]
    public function pageCalculatesCorrectlyForVariousOffsets(): void
    {
        $result1 = new EventQueryResult(items: [], total: 100, limit: 10, offset: 0);
        $result2 = new EventQueryResult(items: [], total: 100, limit: 10, offset: 10);
        $result3 = new EventQueryResult(items: [], total: 100, limit: 10, offset: 20);
        $result4 = new EventQueryResult(items: [], total: 100, limit: 10, offset: 90);

        self::assertSame(1, $result1->page());
        self::assertSame(2, $result2->page());
        self::assertSame(3, $result3->page());
        self::assertSame(10, $result4->page());
    }

    #[Test]
    public function totalPagesCalculatesCorrectly(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 25,
            offset: 0,
        );

        self::assertSame(4, $result->totalPages());
    }

    #[Test]
    public function totalPagesRoundsUp(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 101,
            limit: 25,
            offset: 0,
        );

        self::assertSame(5, $result->totalPages());
    }

    #[Test]
    public function totalPagesReturnsOneWhenTotalIsZero(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 0,
            limit: 25,
            offset: 0,
        );

        self::assertSame(0, $result->totalPages());
    }

    #[Test]
    public function totalPagesReturnsOneWhenLimitIsZero(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 0,
            offset: 0,
        );

        self::assertSame(1, $result->totalPages());
    }

    #[Test]
    public function totalPagesReturnsOneForSinglePage(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 10,
            limit: 25,
            offset: 0,
        );

        self::assertSame(1, $result->totalPages());
    }

    #[Test]
    public function totalPagesReturnsExactPagesWhenDivisible(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 10,
            offset: 0,
        );

        self::assertSame(10, $result->totalPages());
    }

    #[Test]
    public function itemsCanBeEmpty(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 0,
            limit: 25,
            offset: 0,
        );

        self::assertSame([], $result->items);
        self::assertCount(0, $result->items);
    }

    #[Test]
    public function itemsPreservesEventData(): void
    {
        $items = [
            [
                'event_id' => 'event-1',
                'event_type' => 'http.request',
                'timestamp_us' => 1_700_000_000_000_000,
                'payload_json' => '{"method":"GET"}',
            ],
        ];

        $result = new EventQueryResult(
            items: $items,
            total: 1,
            limit: 25,
            offset: 0,
        );

        self::assertSame($items[0], $result->items[0]);
        self::assertSame('event-1', $result->items[0]['event_id']);
        self::assertSame('http.request', $result->items[0]['event_type']);
    }

    #[Test]
    public function paginationValuesAreConsistent(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 95,
            limit: 10,
            offset: 40,
        );

        self::assertSame(5, $result->page());
        self::assertSame(10, $result->totalPages());
        self::assertTrue($result->hasMore());
    }

    #[Test]
    public function lastPageHasNoMore(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 95,
            limit: 10,
            offset: 90,
        );

        self::assertSame(10, $result->page());
        self::assertSame(10, $result->totalPages());
        self::assertFalse($result->hasMore());
    }

    #[Test]
    public function singleItemResultWorkCorrectly(): void
    {
        $result = new EventQueryResult(
            items: [['event_id' => 'single']],
            total: 1,
            limit: 50,
            offset: 0,
        );

        self::assertCount(1, $result->items);
        self::assertSame(1, $result->total);
        self::assertSame(1, $result->page());
        self::assertSame(1, $result->totalPages());
        self::assertFalse($result->hasMore());
    }

    #[Test]
    public function largeOffsetBeyondTotalStillWorks(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 50,
            limit: 10,
            offset: 1000,
        );

        self::assertSame([], $result->items);
        self::assertSame(50, $result->total);
        self::assertSame(101, $result->page());
        self::assertSame(5, $result->totalPages());
        self::assertFalse($result->hasMore());
    }
}
