<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Domain\SegmentDimension;
use Pulsar\Extension\Analytics\Domain\SegmentFilter;
use Pulsar\Extension\Analytics\Domain\SegmentOperator;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Extension\Analytics\Internal\Service\SegmentService;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class SegmentServiceTest extends TestCase
{
    private SegmentService $service;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(\Pulsar\Database\Driver::MySQL);
        $this->service = new SegmentService($this->connection);
    }

    #[Test]
    public function createInsertsSegmentAndReturnsIt(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $filters = [
            new SegmentFilter(SegmentDimension::Country, SegmentOperator::Equals, 'US'),
            new SegmentFilter(SegmentDimension::Browser, SegmentOperator::Contains, 'Chrome'),
        ];

        $result = $this->service->create('site-001', 'US Chrome', $filters);

        self::assertSame('site-001', $result->siteId);
        self::assertSame('US Chrome', $result->name);
        self::assertCount(2, $result->filters);
        self::assertNotEmpty($result->id);
        self::assertSame(SegmentDimension::Country, $result->filters[0]->dimension);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->findById('nonexistent');

        self::assertNull($result);
    }

    #[Test]
    public function findByIdReturnsSegment(): void
    {
        $filtersJson = json_encode([
            ['dimension' => 'country', 'operator' => 'eq', 'value' => 'US'],
        ], JSON_THROW_ON_ERROR);

        $row = new Row([
            'id' => 's-001',
            'site_id' => 'site-001',
            'name' => 'US visitors',
            'filters' => $filtersJson,
            'created_at' => '2025-06-01 12:00:00',
        ]);

        $queryResult = new Result([$row]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->findById('s-001');

        self::assertNotNull($result);
        self::assertSame('s-001', $result->id);
        self::assertSame('US visitors', $result->name);
        self::assertCount(1, $result->filters);
        self::assertSame(SegmentDimension::Country, $result->filters[0]->dimension);
    }

    #[Test]
    public function listForSiteReturnsSegments(): void
    {
        $filtersJson = json_encode([
            ['dimension' => 'browser', 'operator' => 'eq', 'value' => 'Firefox'],
        ], JSON_THROW_ON_ERROR);

        $rows = [
            new Row(['id' => 's-001', 'site_id' => 'site-001', 'name' => 'Firefox', 'filters' => $filtersJson, 'created_at' => '2025-06-01 12:00:00']),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->listForSite('site-001');

        self::assertCount(1, $result);
        self::assertSame('Firefox', $result[0]->name);
    }

    #[Test]
    public function deleteThrowsWhenSegmentNotFound(): void
    {
        $this->connection->method('execute')->willReturn(0);

        $this->expectException(AnalyticsException::class);

        $this->service->delete('nonexistent');
    }

    #[Test]
    public function deleteSucceedsWhenSegmentExists(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $this->service->delete('s-001');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function countVisitorsThrowsWhenSegmentNotFound(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $this->expectException(AnalyticsException::class);

        $this->service->countVisitors('nonexistent', new DateTimeImmutable('-30 days'), new DateTimeImmutable());
    }

    #[Test]
    public function countVisitorsReturnsCount(): void
    {
        $filtersJson = json_encode([
            ['dimension' => 'country', 'operator' => 'eq', 'value' => 'US'],
        ], JSON_THROW_ON_ERROR);

        $segmentRow = new Row([
            'id' => 's-001',
            'site_id' => 'site-001',
            'name' => 'US',
            'filters' => $filtersJson,
            'created_at' => '2025-06-01 12:00:00',
        ]);

        $segmentResult = new Result([$segmentRow]);
        $countRow = new Row(['cnt' => 42]);
        $countResult = new Result([$countRow]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($segmentResult, $countResult);

        $count = $this->service->countVisitors('s-001', new DateTimeImmutable('-30 days'), new DateTimeImmutable());

        self::assertSame(42, $count);
    }

    #[Test]
    public function countVisitorsHandlesContainsOperator(): void
    {
        $filtersJson = json_encode([
            ['dimension' => 'browser', 'operator' => 'contains', 'value' => 'Chrome'],
        ], JSON_THROW_ON_ERROR);

        $segmentRow = new Row([
            'id' => 's-001',
            'site_id' => 'site-001',
            'name' => 'Chrome-like',
            'filters' => $filtersJson,
            'created_at' => '2025-06-01 12:00:00',
        ]);

        $segmentResult = new Result([$segmentRow]);
        $countRow = new Row(['cnt' => 10]);
        $countResult = new Result([$countRow]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($segmentResult, $countResult);

        $count = $this->service->countVisitors('s-001', new DateTimeImmutable('-30 days'), new DateTimeImmutable());

        self::assertSame(10, $count);
    }

    #[Test]
    public function countVisitorsJoinsSessionsForEntryPage(): void
    {
        $filtersJson = json_encode([
            ['dimension' => 'entry_page', 'operator' => 'eq', 'value' => '/landing'],
        ], JSON_THROW_ON_ERROR);

        $segmentRow = new Row([
            'id' => 's-002',
            'site_id' => 'site-001',
            'name' => 'Landing Entry',
            'filters' => $filtersJson,
            'created_at' => '2025-06-01 12:00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $segmentResult = new Result([$segmentRow]);
        $countRow = new Row(['cnt' => 5]);
        $countResult = new Result([$countRow]);

        $connection->method('query')
            ->willReturnOnConsecutiveCalls($segmentResult, $countResult);

        $service = new SegmentService($connection);

        $count = $service->countVisitors('s-002', new DateTimeImmutable('-30 days'), new DateTimeImmutable());

        self::assertSame(5, $count);
    }
}
