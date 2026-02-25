<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Query\EventQueryResult;

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
    public function hasMoreReturnsFalseAtEnd(): void
    {
        $result = new EventQueryResult(
            items: [['id' => 1]],
            total: 5,
            limit: 10,
            offset: 0,
        );

        self::assertFalse($result->hasMore());
    }

    #[Test]
    public function pageReturnsCorrectPageNumber(): void
    {
        $result = new EventQueryResult(items: [], total: 100, limit: 10, offset: 20);

        self::assertSame(3, $result->page());
    }

    #[Test]
    public function pageReturnsOneForFirstPage(): void
    {
        $result = new EventQueryResult(items: [], total: 100, limit: 10, offset: 0);

        self::assertSame(1, $result->page());
    }

    #[Test]
    public function totalPagesCalculatesCorrectly(): void
    {
        $result = new EventQueryResult(items: [], total: 25, limit: 10, offset: 0);

        self::assertSame(3, $result->totalPages());
    }

    #[Test]
    public function totalPagesReturnsOneForZeroLimit(): void
    {
        $result = new EventQueryResult(items: [], total: 25, limit: 0, offset: 0);

        self::assertSame(1, $result->totalPages());
    }

    #[Test]
    public function totalPagesReturnsOneForExactDivision(): void
    {
        $result = new EventQueryResult(items: [], total: 20, limit: 10, offset: 0);

        self::assertSame(2, $result->totalPages());
    }

    #[Test]
    public function pageReturnsOneForZeroLimit(): void
    {
        $result = new EventQueryResult(items: [], total: 10, limit: 0, offset: 0);

        self::assertSame(1, $result->page());
    }
}
