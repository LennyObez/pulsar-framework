<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Query\EventFilter;

#[CoversClass(EventFilter::class)]
final class EventFilterTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $filter = new EventFilter(
            eventTypes: [EventType::HttpRequest, EventType::DatabaseQuery],
            requestId: 'req-123',
            traceId: 'trace-abc',
            jobId: 'job-456',
            tenantHash: 'tenant-hash-789',
            sinceUs: 1_700_000_000_000_000,
            untilUs: 1_700_000_001_000_000,
            sinceId: 100,
            search: 'search term',
        );

        self::assertSame([EventType::HttpRequest, EventType::DatabaseQuery], $filter->eventTypes);
        self::assertSame('req-123', $filter->requestId);
        self::assertSame('trace-abc', $filter->traceId);
        self::assertSame('job-456', $filter->jobId);
        self::assertSame('tenant-hash-789', $filter->tenantHash);
        self::assertSame(1_700_000_000_000_000, $filter->sinceUs);
        self::assertSame(1_700_000_001_000_000, $filter->untilUs);
        self::assertSame(100, $filter->sinceId);
        self::assertSame('search term', $filter->search);
    }

    #[Test]
    public function constructorDefaultsToEmptyAndNull(): void
    {
        $filter = new EventFilter();

        self::assertSame([], $filter->eventTypes);
        self::assertNull($filter->requestId);
        self::assertNull($filter->traceId);
        self::assertNull($filter->jobId);
        self::assertNull($filter->tenantHash);
        self::assertNull($filter->sinceUs);
        self::assertNull($filter->untilUs);
        self::assertNull($filter->sinceId);
        self::assertNull($filter->search);
    }

    #[Test]
    public function isEmptyReturnsTrueForDefaultFilter(): void
    {
        $filter = new EventFilter();

        self::assertTrue($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenEventTypesSet(): void
    {
        $filter = new EventFilter(eventTypes: [EventType::HttpRequest]);

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenRequestIdSet(): void
    {
        $filter = new EventFilter(requestId: 'req-123');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenTraceIdSet(): void
    {
        $filter = new EventFilter(traceId: 'trace-abc');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenJobIdSet(): void
    {
        $filter = new EventFilter(jobId: 'job-456');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenTenantHashSet(): void
    {
        $filter = new EventFilter(tenantHash: 'tenant-hash');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenSinceUsSet(): void
    {
        $filter = new EventFilter(sinceUs: 1_700_000_000_000_000);

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenUntilUsSet(): void
    {
        $filter = new EventFilter(untilUs: 1_700_000_001_000_000);

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenSinceIdSet(): void
    {
        $filter = new EventFilter(sinceId: 100);

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenSearchSet(): void
    {
        $filter = new EventFilter(search: 'search term');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function toStoreFiltersReturnsEmptyArrayForDefaultFilter(): void
    {
        $filter = new EventFilter();

        self::assertSame([], $filter->toStoreFilters());
    }

    #[Test]
    public function toStoreFiltersConvertsEventTypesToValues(): void
    {
        $filter = new EventFilter(
            eventTypes: [EventType::HttpRequest, EventType::DatabaseQuery],
        );

        $storeFilters = $filter->toStoreFilters();

        self::assertSame(
            [EventType::HttpRequest->value, EventType::DatabaseQuery->value],
            $storeFilters['event_type'],
        );
    }

    #[Test]
    public function toStoreFiltersIncludesRequestId(): void
    {
        $filter = new EventFilter(requestId: 'req-123');

        $storeFilters = $filter->toStoreFilters();

        self::assertSame('req-123', $storeFilters['request_id']);
    }

    #[Test]
    public function toStoreFiltersIncludesTraceId(): void
    {
        $filter = new EventFilter(traceId: 'trace-abc');

        $storeFilters = $filter->toStoreFilters();

        self::assertSame('trace-abc', $storeFilters['trace_id']);
    }

    #[Test]
    public function toStoreFiltersIncludesJobId(): void
    {
        $filter = new EventFilter(jobId: 'job-456');

        $storeFilters = $filter->toStoreFilters();

        self::assertSame('job-456', $storeFilters['job_id']);
    }

    #[Test]
    public function toStoreFiltersIncludesTenantHash(): void
    {
        $filter = new EventFilter(tenantHash: 'tenant-hash-789');

        $storeFilters = $filter->toStoreFilters();

        self::assertSame('tenant-hash-789', $storeFilters['tenant_hash']);
    }

    #[Test]
    public function toStoreFiltersIncludesSinceUs(): void
    {
        $filter = new EventFilter(sinceUs: 1_700_000_000_000_000);

        $storeFilters = $filter->toStoreFilters();

        self::assertSame(1_700_000_000_000_000, $storeFilters['since_us']);
    }

    #[Test]
    public function toStoreFiltersIncludesUntilUs(): void
    {
        $filter = new EventFilter(untilUs: 1_700_000_001_000_000);

        $storeFilters = $filter->toStoreFilters();

        self::assertSame(1_700_000_001_000_000, $storeFilters['until_us']);
    }

    #[Test]
    public function toStoreFiltersIncludesSinceId(): void
    {
        $filter = new EventFilter(sinceId: 100);

        $storeFilters = $filter->toStoreFilters();

        self::assertSame(100, $storeFilters['since_id']);
    }

    #[Test]
    public function toStoreFiltersExcludesSearch(): void
    {
        $filter = new EventFilter(search: 'search term');

        $storeFilters = $filter->toStoreFilters();

        self::assertArrayNotHasKey('search', $storeFilters);
    }

    #[Test]
    public function toStoreFiltersIncludesAllSetValues(): void
    {
        $filter = new EventFilter(
            eventTypes: [EventType::HttpRequest],
            requestId: 'req-123',
            traceId: 'trace-abc',
            jobId: 'job-456',
            tenantHash: 'tenant-hash-789',
            sinceUs: 1_700_000_000_000_000,
            untilUs: 1_700_000_001_000_000,
            sinceId: 100,
            search: 'search term',
        );

        $storeFilters = $filter->toStoreFilters();

        self::assertArrayHasKey('event_type', $storeFilters);
        self::assertArrayHasKey('request_id', $storeFilters);
        self::assertArrayHasKey('trace_id', $storeFilters);
        self::assertArrayHasKey('job_id', $storeFilters);
        self::assertArrayHasKey('tenant_hash', $storeFilters);
        self::assertArrayHasKey('since_us', $storeFilters);
        self::assertArrayHasKey('until_us', $storeFilters);
        self::assertArrayHasKey('since_id', $storeFilters);
        self::assertArrayNotHasKey('search', $storeFilters);
    }

    #[Test]
    public function toStoreFiltersExcludesNullValues(): void
    {
        $filter = new EventFilter(requestId: 'req-123');

        $storeFilters = $filter->toStoreFilters();

        self::assertCount(1, $storeFilters);
        self::assertArrayHasKey('request_id', $storeFilters);
    }

    #[Test]
    public function eventTypesSingleItemConvertsCorrectly(): void
    {
        $filter = new EventFilter(eventTypes: [EventType::Exception]);

        $storeFilters = $filter->toStoreFilters();

        self::assertSame([EventType::Exception->value], $storeFilters['event_type']);
    }

    #[Test]
    public function eventTypesMultipleItemsConvertsCorrectly(): void
    {
        $filter = new EventFilter(
            eventTypes: [
                EventType::HttpRequest,
                EventType::HttpResponse,
                EventType::DatabaseQuery,
                EventType::CacheOperation,
            ],
        );

        $storeFilters = $filter->toStoreFilters();

        self::assertSame(
            [
                EventType::HttpRequest->value,
                EventType::HttpResponse->value,
                EventType::DatabaseQuery->value,
                EventType::CacheOperation->value,
            ],
            $storeFilters['event_type'],
        );
    }
}
