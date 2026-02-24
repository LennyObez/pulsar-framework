<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Query\EventFilter;

#[CoversClass(EventFilter::class)]
final class EventFilterTest extends TestCase
{
    #[Test]
    public function emptyFilterIsEmpty(): void
    {
        $filter = new EventFilter();

        self::assertTrue($filter->isEmpty());
    }

    #[Test]
    public function filterWithEventTypesIsNotEmpty(): void
    {
        $filter = new EventFilter(eventTypes: [EventType::HttpRequest]);

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function filterWithRequestIdIsNotEmpty(): void
    {
        $filter = new EventFilter(requestId: 'req-123');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function filterWithTraceIdIsNotEmpty(): void
    {
        $filter = new EventFilter(traceId: 'trace-abc');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function filterWithSinceUsIsNotEmpty(): void
    {
        $filter = new EventFilter(sinceUs: 1000000);

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function toStoreFiltersConvertsEventTypes(): void
    {
        $filter = new EventFilter(
            eventTypes: [EventType::HttpRequest, EventType::DatabaseQuery],
        );

        $storeFilters = $filter->toStoreFilters();

        self::assertSame(['http.request', 'db.query'], $storeFilters['event_type']);
    }

    #[Test]
    public function toStoreFiltersIncludesAllSetFields(): void
    {
        $filter = new EventFilter(
            requestId: 'req-1',
            traceId: 'trace-1',
            jobId: 'job-1',
            tenantHash: 'tenant-1',
            sinceUs: 1000,
            untilUs: 2000,
            sinceId: 5,
        );

        $storeFilters = $filter->toStoreFilters();

        self::assertSame('req-1', $storeFilters['request_id']);
        self::assertSame('trace-1', $storeFilters['trace_id']);
        self::assertSame('job-1', $storeFilters['job_id']);
        self::assertSame('tenant-1', $storeFilters['tenant_hash']);
        self::assertSame(1000, $storeFilters['since_us']);
        self::assertSame(2000, $storeFilters['until_us']);
        self::assertSame(5, $storeFilters['since_id']);
    }

    #[Test]
    public function toStoreFiltersOmitsNullFields(): void
    {
        $filter = new EventFilter(requestId: 'req-1');

        $storeFilters = $filter->toStoreFilters();

        self::assertArrayHasKey('request_id', $storeFilters);
        self::assertArrayNotHasKey('trace_id', $storeFilters);
        self::assertArrayNotHasKey('event_type', $storeFilters);
    }
}
