<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Query\EventQueryResult;

#[CoversClass(EventQueryResult::class)]
final class EventQueryResultTest extends TestCase
{
    #[Test]
    public function hasMoreReturnsTrueWhenMoreItemsExist(): void
    {
        $result = new EventQueryResult(
            items: [['id' => 1]],
            total: 100,
            limit: 10,
            offset: 0,
        );

        self::assertTrue($result->hasMore());
    }

    #[Test]
    public function hasMoreReturnsFalseOnLastPage(): void
    {
        $result = new EventQueryResult(
            items: [['id' => 10]],
            total: 10,
            limit: 10,
            offset: 0,
        );

        self::assertFalse($result->hasMore());
    }

    #[Test]
    public function pageReturnsCurrentPageNumber(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 10,
            offset: 0,
        );

        self::assertSame(1, $result->page());
    }

    #[Test]
    public function pageReturnsCorrectPageForOffset(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 100,
            limit: 10,
            offset: 20,
        );

        self::assertSame(3, $result->page());
    }

    #[Test]
    public function totalPagesCalculatesCorrectly(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 25,
            limit: 10,
            offset: 0,
        );

        self::assertSame(3, $result->totalPages());
    }

    #[Test]
    public function totalPagesReturnsOneForZeroLimit(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 10,
            limit: 0,
            offset: 0,
        );

        self::assertSame(1, $result->totalPages());
    }

    #[Test]
    public function pageReturnsOneForZeroLimit(): void
    {
        $result = new EventQueryResult(
            items: [],
            total: 10,
            limit: 0,
            offset: 0,
        );

        self::assertSame(1, $result->page());
    }
}
