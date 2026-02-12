<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Query\EventQuery;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

final class EventQueryTest extends TestCase
{
    #[Test]
    public function filterBuildsCorrectEventFilter(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->type(EventType::HttpRequest, EventType::Exception)
            ->requestId('req-1')
            ->traceId('trace-1')
            ->jobId('job-1')
            ->since(1000)
            ->until(2000)
            ->sinceId(42)
            ->search('error');

        $filter = $query->filter();

        self::assertSame([EventType::HttpRequest, EventType::Exception], $filter->eventTypes);
        self::assertSame('req-1', $filter->requestId);
        self::assertSame('trace-1', $filter->traceId);
        self::assertSame('job-1', $filter->jobId);
        self::assertSame(1000, $filter->sinceUs);
        self::assertSame(2000, $filter->untilUs);
        self::assertSame(42, $filter->sinceId);
        self::assertSame('error', $filter->search);
    }

    #[Test]
    public function limitClampsToMinimumOne(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->limit(-5);
        $result = $query->get();

        self::assertSame(1, $result->limit);
    }

    #[Test]
    public function offsetClampsToMinimumZero(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->offset(-10);
        $result = $query->get();

        self::assertSame(0, $result->offset);
    }

    #[Test]
    public function pageCalculatesOffsetCorrectly(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->page(3, 25);
        $result = $query->get();

        self::assertSame(25, $result->limit);
        self::assertSame(50, $result->offset);
    }

    #[Test]
    public function getReturnsQueryResult(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([['event_id' => 'e1'], ['event_id' => 'e2']]);
        $store->method('count')->willReturn(10);

        $query = new EventQuery($store);
        $result = $query->limit(2)->get();

        self::assertCount(2, $result->items);
        self::assertSame(10, $result->total);
        self::assertSame(2, $result->limit);
    }

    #[Test]
    public function tenantHashSetsFilter(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);
        $query->tenantHash('abc123');

        $filter = $query->filter();

        self::assertSame('abc123', $filter->tenantHash);
    }
}
