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
use Pulsar\Extension\Analytics\Internal\Service\CustomEventService;

use function count;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class CustomEventServiceTest extends TestCase
{
    private CustomEventService $service;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->service = new CustomEventService($this->connection);
    }

    #[Test]
    public function getEventNamesReturnsEventNameCounts(): void
    {
        $rows = [
            new Row(['event_name' => 'click_cta', 'count' => 100, 'visitors' => 80]),
            new Row(['event_name' => 'signup', 'count' => 50, 'visitors' => 45]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getEventNames(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertCount(2, $result);
        self::assertSame('click_cta', $result[0]['event_name']);
        self::assertSame(100, $result[0]['count']);
        self::assertSame(80, $result[0]['visitors']);
    }

    #[Test]
    public function getEventNamesReturnsEmptyForNoEvents(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getEventNames(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function getEventNamesRespectsLimit(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(self::anything(), self::callback(static fn(array $p): bool => $p['limit'] === 5))
            ->willReturn(new Result([]));

        $service = new CustomEventService($connection);

        $service->getEventNames('site-001', new DateTimeImmutable('-30 days'), new DateTimeImmutable(), 5);
    }

    #[Test]
    public function getEventPropertiesReturnsPropertyBreakdown(): void
    {
        $propsJson1 = json_encode(['plan' => 'pro', 'source' => 'organic'], JSON_THROW_ON_ERROR);
        $propsJson2 = json_encode(['plan' => 'free', 'source' => 'organic'], JSON_THROW_ON_ERROR);
        $propsJson3 = json_encode(['plan' => 'pro'], JSON_THROW_ON_ERROR);

        $rows = [
            new Row(['event_props' => $propsJson1]),
            new Row(['event_props' => $propsJson2]),
            new Row(['event_props' => $propsJson3]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getEventProperties(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            'signup',
        );

        self::assertNotEmpty($result);
        // Should contain entries for plan and source properties
        $properties = array_column($result, 'property');
        self::assertContains('plan', $properties);
        self::assertContains('source', $properties);
    }

    #[Test]
    public function getEventPropertiesSkipsNullProps(): void
    {
        $rows = [
            new Row(['event_props' => null]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getEventProperties(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            'click',
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function getEventPropertiesSortsByCountDescending(): void
    {
        $propsJson1 = json_encode(['color' => 'blue'], JSON_THROW_ON_ERROR);
        $propsJson2 = json_encode(['color' => 'blue'], JSON_THROW_ON_ERROR);
        $propsJson3 = json_encode(['color' => 'red'], JSON_THROW_ON_ERROR);

        $rows = [
            new Row(['event_props' => $propsJson1]),
            new Row(['event_props' => $propsJson2]),
            new Row(['event_props' => $propsJson3]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getEventProperties(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            'click',
        );

        self::assertSame(2, $result[0]['count']); // blue: 2
        self::assertSame(1, $result[1]['count']); // red: 1
    }

    #[Test]
    public function getEventPropertiesRespectsLimit(): void
    {
        $rows = [];

        for ($i = 0; $i < 30; $i++) {
            $rows[] = new Row(['event_props' => json_encode(['key' . $i => 'val'], JSON_THROW_ON_ERROR)]);
        }

        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getEventProperties(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            'click',
            10,
        );

        self::assertLessThanOrEqual(10, count($result));
    }

    #[Test]
    public function getEventTimeseriesReturnsTimeseriesData(): void
    {
        $rows = [
            new Row(['date' => '2025-01-01', 'count' => 10]),
            new Row(['date' => '2025-01-02', 'count' => 15]),
            new Row(['date' => '2025-01-03', 'count' => 8]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getEventTimeseries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            'signup',
        );

        self::assertCount(3, $result);
        self::assertSame('2025-01-01', $result[0]['date']);
        self::assertSame(10, $result[0]['count']);
        self::assertSame(15, $result[1]['count']);
    }

    #[Test]
    public function getEventTimeseriesReturnsEmptyForNoData(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getEventTimeseries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            'nonexistent',
        );

        self::assertSame([], $result);
    }
}
