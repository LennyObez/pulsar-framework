<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Query\EventFilter;
use Pulsar\Studio\Console\Query\EventQuery;
use Pulsar\Studio\Console\Query\EventQueryResult;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(EventQuery::class)]
#[CoversClass(EventFilter::class)]
#[CoversClass(EventQueryResult::class)]
final class EventQueryTest extends TestCase
{
    #[Test]
    public function typeAddsEventTypesToQuery(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->type(EventType::HttpRequest, EventType::DatabaseQuery);
        $filter = $query->filter();

        self::assertSame([EventType::HttpRequest, EventType::DatabaseQuery], $filter->eventTypes);
    }

    #[Test]
    public function typeAccumulatesMultipleCalls(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->type(EventType::HttpRequest);
        $query->type(EventType::DatabaseQuery);
        $filter = $query->filter();

        self::assertSame([EventType::HttpRequest, EventType::DatabaseQuery], $filter->eventTypes);
    }

    #[Test]
    public function typeReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->type(EventType::HttpRequest);

        self::assertSame($query, $result);
    }

    #[Test]
    public function requestIdSetsRequestId(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->requestId('req-123');
        $filter = $query->filter();

        self::assertSame('req-123', $filter->requestId);
    }

    #[Test]
    public function requestIdReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->requestId('req-123');

        self::assertSame($query, $result);
    }

    #[Test]
    public function traceIdSetsTraceId(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->traceId('trace-abc');
        $filter = $query->filter();

        self::assertSame('trace-abc', $filter->traceId);
    }

    #[Test]
    public function traceIdReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->traceId('trace-abc');

        self::assertSame($query, $result);
    }

    #[Test]
    public function jobIdSetsJobId(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->jobId('job-456');
        $filter = $query->filter();

        self::assertSame('job-456', $filter->jobId);
    }

    #[Test]
    public function jobIdReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->jobId('job-456');

        self::assertSame($query, $result);
    }

    #[Test]
    public function tenantHashSetsTenantHash(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->tenantHash('tenant-hash-789');
        $filter = $query->filter();

        self::assertSame('tenant-hash-789', $filter->tenantHash);
    }

    #[Test]
    public function tenantHashReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->tenantHash('tenant-hash-789');

        self::assertSame($query, $result);
    }

    #[Test]
    public function sinceSetsSinceUs(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->since(1_700_000_000_000_000);
        $filter = $query->filter();

        self::assertSame(1_700_000_000_000_000, $filter->sinceUs);
    }

    #[Test]
    public function sinceReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->since(1_700_000_000_000_000);

        self::assertSame($query, $result);
    }

    #[Test]
    public function untilSetsUntilUs(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->until(1_700_000_001_000_000);
        $filter = $query->filter();

        self::assertSame(1_700_000_001_000_000, $filter->untilUs);
    }

    #[Test]
    public function untilReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->until(1_700_000_001_000_000);

        self::assertSame($query, $result);
    }

    #[Test]
    public function sinceIdSetsSinceId(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->sinceId(100);
        $filter = $query->filter();

        self::assertSame(100, $filter->sinceId);
    }

    #[Test]
    public function sinceIdReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->sinceId(100);

        self::assertSame($query, $result);
    }

    #[Test]
    public function searchSetsSearch(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query->search('search term');
        $filter = $query->filter();

        self::assertSame('search term', $filter->search);
    }

    #[Test]
    public function searchReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->search('search term');

        self::assertSame($query, $result);
    }

    #[Test]
    public function limitSetsLimit(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->limit(25);
        $result = $query->get();

        self::assertSame(25, $result->limit);
    }

    #[Test]
    public function limitReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->limit(25);

        self::assertSame($query, $result);
    }

    #[Test]
    public function limitEnforcesMinimumOfOne(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->limit(0);
        $result = $query->get();

        self::assertSame(1, $result->limit);
    }

    #[Test]
    public function limitEnforcesMinimumOfOneForNegative(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->limit(-10);
        $result = $query->get();

        self::assertSame(1, $result->limit);
    }

    #[Test]
    public function offsetSetsOffset(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->offset(50);
        $result = $query->get();

        self::assertSame(50, $result->offset);
    }

    #[Test]
    public function offsetReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->offset(50);

        self::assertSame($query, $result);
    }

    #[Test]
    public function offsetEnforcesMinimumOfZero(): void
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
    public function pageCalculatesLimitAndOffset(): void
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
    public function pageDefaultsToPerPage50(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->page(2);
        $result = $query->get();

        self::assertSame(50, $result->limit);
        self::assertSame(50, $result->offset);
    }

    #[Test]
    public function pageReturnsSelfForChaining(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $result = $query->page(1);

        self::assertSame($query, $result);
    }

    #[Test]
    public function pageEnforcesMinimumPageOfOne(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->page(0, 25);
        $result = $query->get();

        self::assertSame(0, $result->offset);
    }

    #[Test]
    public function pageEnforcesMinimumPerPageOfOne(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $query->page(2, 0);
        $result = $query->get();

        self::assertSame(1, $result->limit);
    }

    #[Test]
    public function filterReturnsEventFilterWithAllValues(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $query = new EventQuery($store);

        $query
            ->type(EventType::HttpRequest)
            ->requestId('req-123')
            ->traceId('trace-abc')
            ->jobId('job-456')
            ->tenantHash('tenant-hash')
            ->since(1_700_000_000_000_000)
            ->until(1_700_000_001_000_000)
            ->sinceId(100)
            ->search('term');

        $filter = $query->filter();

        self::assertSame([EventType::HttpRequest], $filter->eventTypes);
        self::assertSame('req-123', $filter->requestId);
        self::assertSame('trace-abc', $filter->traceId);
        self::assertSame('job-456', $filter->jobId);
        self::assertSame('tenant-hash', $filter->tenantHash);
        self::assertSame(1_700_000_000_000_000, $filter->sinceUs);
        self::assertSame(1_700_000_001_000_000, $filter->untilUs);
        self::assertSame(100, $filter->sinceId);
        self::assertSame('term', $filter->search);
    }

    #[Test]
    public function getExecutesQueryOnStore(): void
    {
        $expectedItems = [
            ['event_id' => 'event-1', 'event_type' => 'http.request'],
            ['event_id' => 'event-2', 'event_type' => 'db.query'],
        ];

        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 50, 0)
            ->willReturn($expectedItems);
        $store->expects(self::once())
            ->method('count')
            ->with([])
            ->willReturn(2);

        $query = new EventQuery($store);
        $result = $query->get();

        self::assertSame($expectedItems, $result->items);
        self::assertSame(2, $result->total);
    }

    #[Test]
    public function getPassesFiltersToStore(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                ['request_id' => 'req-123'],
                50,
                0,
            )
            ->willReturn([]);
        $store->expects(self::once())
            ->method('count')
            ->with(['request_id' => 'req-123'])
            ->willReturn(0);

        $query = new EventQuery($store);
        $query->requestId('req-123')->get();
    }

    #[Test]
    public function getPassesLimitAndOffsetToStore(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 25, 100)
            ->willReturn([]);
        $store->expects(self::once())
            ->method('count')
            ->with([])
            ->willReturn(0);

        $query = new EventQuery($store);
        $query->limit(25)->offset(100)->get();
    }

    #[Test]
    public function getReturnsEventQueryResult(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $result = $query->get();

        self::assertInstanceOf(EventQueryResult::class, $result);
    }

    #[Test]
    public function chainingWorksCorrectly(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(100);

        $result = new EventQuery($store)
            ->type(EventType::HttpRequest)
            ->requestId('req-123')
            ->limit(10)
            ->offset(20)
            ->get();

        self::assertSame(100, $result->total);
        self::assertSame(10, $result->limit);
        self::assertSame(20, $result->offset);
    }

    #[Test]
    public function defaultLimitIs50(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $result = $query->get();

        self::assertSame(50, $result->limit);
    }

    #[Test]
    public function defaultOffsetIsZero(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $query = new EventQuery($store);
        $result = $query->get();

        self::assertSame(0, $result->offset);
    }
}
