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
use Pulsar\Extension\Analytics\Internal\Service\SearchAnalyticsService;

final class SearchAnalyticsServiceTest extends TestCase
{
    private SearchAnalyticsService $service;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->service = new SearchAnalyticsService($this->connection);
    }

    #[Test]
    public function getTopQueriesReturnsSearchQueryObjects(): void
    {
        $searchRows = [
            new Row(['query' => '"pulsar framework"', 'count' => 45, 'avg_results' => 12]),
            new Row(['query' => '"php analytics"', 'count' => 30, 'avg_results' => 8]),
        ];
        $clickRows = [
            new Row(['query' => '"pulsar framework"', 'clicks' => 10]),
            new Row(['query' => '"php analytics"', 'clicks' => 5]),
        ];

        $this->connection->method('query')->willReturnOnConsecutiveCalls(
            new Result($searchRows),
            new Result($clickRows),
        );

        $result = $this->service->getTopQueries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertCount(2, $result);
        self::assertSame('pulsar framework', $result[0]->query);
        self::assertSame(45, $result[0]->count);
        self::assertSame(12, $result[0]->resultCount);
    }

    #[Test]
    public function getTopQueriesSkipsNullQueryValues(): void
    {
        $searchRows = [
            new Row(['query' => null, 'count' => 10, 'avg_results' => 0]),
            new Row(['query' => '"valid"', 'count' => 5, 'avg_results' => 3]),
        ];

        $this->connection->method('query')->willReturnOnConsecutiveCalls(
            new Result($searchRows),
            new Result([]),
        );

        $result = $this->service->getTopQueries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertCount(1, $result);
        self::assertSame('valid', $result[0]->query);
    }

    #[Test]
    public function getTopQueriesReturnsEmptyForNoData(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $result = $this->service->getTopQueries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function getZeroResultQueriesReturnsQueriesWithZeroResults(): void
    {
        $rows = [
            new Row(['query' => '"nonexistent feature"', 'count' => 20]),
            new Row(['query' => '"old product"', 'count' => 15]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getZeroResultQueries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertCount(2, $result);
        self::assertSame('nonexistent feature', $result[0]->query);
        self::assertSame(0, $result[0]->resultCount);
        self::assertSame(20, $result[0]->count);
    }

    #[Test]
    public function getZeroResultQueriesSkipsNullQueries(): void
    {
        $rows = [
            new Row(['query' => null, 'count' => 5]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getZeroResultQueries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function getOverviewReturnsSummaryMetrics(): void
    {
        $overviewRow = new Row([
            'total_searches' => 500,
            'unique_queries' => 120,
            'zero_results' => 42,
        ]);
        $clickRow = new Row(['click_count' => 25]);

        $this->connection->method('query')->willReturnOnConsecutiveCalls(
            new Result([$overviewRow]),
            new Result([$clickRow]),
        );

        $result = $this->service->getOverview(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame(500, $result['total_searches']);
        self::assertSame(120, $result['unique_queries']);
        self::assertSame(8.4, $result['zero_result_rate']);
        self::assertSame(5.0, $result['avg_click_through_rate']);
    }

    #[Test]
    public function getOverviewReturnsZerosForNoData(): void
    {
        $overviewRow = new Row([
            'total_searches' => 0,
            'unique_queries' => 0,
            'zero_results' => 0,
        ]);
        $clickRow = new Row(['click_count' => 0]);

        $this->connection->method('query')->willReturnOnConsecutiveCalls(
            new Result([$overviewRow]),
            new Result([$clickRow]),
        );

        $result = $this->service->getOverview(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame(0, $result['total_searches']);
        self::assertSame(0, $result['unique_queries']);
        self::assertSame(0.0, $result['zero_result_rate']);
    }

    #[Test]
    public function getOverviewHandlesNullRow(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $result = $this->service->getOverview(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame(0, $result['total_searches']);
        self::assertSame(0, $result['unique_queries']);
        self::assertSame(0.0, $result['zero_result_rate']);
    }

    #[Test]
    public function getTopQueriesRespectsLimit(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                new Result([]),
                new Result([]),
            );

        $service = new SearchAnalyticsService($connection);

        $service->getTopQueries('site-001', new DateTimeImmutable('-30 days'), new DateTimeImmutable(), 5);
    }
}
