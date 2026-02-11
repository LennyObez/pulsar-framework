<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Query\EventQuery;
use Pulsar\Extension\Studio\Console\Query\EventQueryResult;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(EventQuery::class)]
final class EventQueryTest extends TestCase
{
    #[Test]
    public function filterBuildsCorrectEventFilter(): void
    {
        $store = $this->createStub(EventStoreInterface::class);

        $query = new EventQuery($store);
        $query->type(EventType::HttpRequest, EventType::DatabaseQuery)
            ->requestId('req-1')
            ->traceId('trace-1')
            ->jobId('job-1')
            ->tenantHash('tenant-1')
            ->since(1000)
            ->until(2000)
            ->sinceId(5)
            ->search('test');

        $filter = $query->filter();

        self::assertSame([EventType::HttpRequest, EventType::DatabaseQuery], $filter->eventTypes);
        self::assertSame('req-1', $filter->requestId);
        self::assertSame('trace-1', $filter->traceId);
        self::assertSame('job-1', $filter->jobId);
        self::assertSame('tenant-1', $filter->tenantHash);
        self::assertSame(1000, $filter->sinceUs);
        self::assertSame(2000, $filter->untilUs);
        self::assertSame(5, $filter->sinceId);
        self::assertSame('test', $filter->search);
    }

    #[Test]
    public function limitClampsToMinimumOne(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $result = $query->limit(-5)->get();

        self::assertSame(1, $result->limit);
    }

    #[Test]
    public function offsetClampsToMinimumZero(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $result = $query->offset(-10)->get();

        self::assertSame(0, $result->offset);
    }

    #[Test]
    public function pageCalculatesCorrectLimitAndOffset(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(100);

        $query = new EventQuery($store);
        $result = $query->page(3, 20)->get();

        self::assertSame(20, $result->limit);
        self::assertSame(40, $result->offset);
    }

    #[Test]
    public function getReturnsEventQueryResult(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([['id' => 1], ['id' => 2]]);
        $store->method('count')->willReturn(50);

        $query = new EventQuery($store);
        $result = $query->limit(10)->get();

        self::assertInstanceOf(EventQueryResult::class, $result);
        self::assertCount(2, $result->items);
        self::assertSame(50, $result->total);
    }
}
