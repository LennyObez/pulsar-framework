<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Query\EventFilter;

final class EventFilterTest extends TestCase
{
    #[Test]
    public function isEmptyReturnsTrueByDefault(): void
    {
        $filter = new EventFilter();

        self::assertTrue($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWithEventTypes(): void
    {
        $filter = new EventFilter(eventTypes: [EventType::HttpRequest]);

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWithRequestId(): void
    {
        $filter = new EventFilter(requestId: 'req-1');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWithTraceId(): void
    {
        $filter = new EventFilter(traceId: 'trace-1');

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWithTimeRange(): void
    {
        $filter = new EventFilter(sinceUs: 1000, untilUs: 2000);

        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function toStoreFiltersConvertsEventTypes(): void
    {
        $filter = new EventFilter(eventTypes: [EventType::HttpRequest, EventType::Exception]);
        $filters = $filter->toStoreFilters();

        self::assertSame(['http.request', 'exception'], $filters['event_type']);
    }

    #[Test]
    public function toStoreFiltersIncludesAllSetFields(): void
    {
        $filter = new EventFilter(
            requestId: 'req-1',
            traceId: 'trace-1',
            jobId: 'job-1',
            tenantHash: 'tenant-hash',
            sinceUs: 1000,
            untilUs: 2000,
            sinceId: 42,
        );

        $filters = $filter->toStoreFilters();

        self::assertSame('req-1', $filters['request_id']);
        self::assertSame('trace-1', $filters['trace_id']);
        self::assertSame('job-1', $filters['job_id']);
        self::assertSame('tenant-hash', $filters['tenant_hash']);
        self::assertSame(1000, $filters['since_us']);
        self::assertSame(2000, $filters['until_us']);
        self::assertSame(42, $filters['since_id']);
    }

    #[Test]
    public function toStoreFiltersExcludesNullFields(): void
    {
        $filter = new EventFilter(requestId: 'req-1');
        $filters = $filter->toStoreFilters();

        self::assertArrayNotHasKey('trace_id', $filters);
        self::assertArrayNotHasKey('event_type', $filters);
        self::assertArrayNotHasKey('since_us', $filters);
    }
}
