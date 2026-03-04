<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\DailyStats;
use Pulsar\Extension\Analytics\Internal\Repository\DbHourlyStatsRepository;
use Pulsar\Extension\Analytics\Internal\Service\AggregationService;

#[CoversClass(AggregationService::class)]
final class AggregationServiceTest extends TestCase
{
    private DailyStatsRepositoryInterface&Stub $dailyStatsRepo;
    private ConnectionInterface&Stub $hourlyRepoConn;
    private DbHourlyStatsRepository $hourlyStatsRepo;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->dailyStatsRepo = $this->createStub(DailyStatsRepositoryInterface::class);
        $this->connection = $this->createStub(ConnectionInterface::class);

        // DbHourlyStatsRepository is final readonly, so we construct it with a stub connection
        $this->hourlyRepoConn = $this->createStub(ConnectionInterface::class);
        $this->hourlyRepoConn->method('driver')->willReturn(Driver::SQLite);
        $this->hourlyRepoConn->method('execute')->willReturn(0);
        $this->hourlyStatsRepo = new DbHourlyStatsRepository($this->hourlyRepoConn);
    }

    private function service(?ConnectionInterface $conn = null, ?DailyStatsRepositoryInterface $dailyRepo = null): AggregationService
    {
        return new AggregationService(
            $dailyRepo ?? $this->dailyStatsRepo,
            $this->hourlyStatsRepo,
            $conn ?? $this->connection,
        );
    }

    #[Test]
    public function aggregateHourlyWithDataDoesNotThrow(): void
    {
        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls(
                new Result([new Row([
                    'site_id' => 'site-1',
                    'visitors' => 42,
                    'pageviews' => 150,
                    'sessions' => 35,
                ])]),
                new Result([new Row(['cnt' => 12])]),
                new Result([new Row([
                    'bounce_rate' => 45.5,
                    'avg_duration' => 120.3,
                ])]),
            );

        $this->service()->aggregateHourly(new DateTimeImmutable('2026-03-15 14:30:00'), 'site-1');
        self::addToAssertionCount(1);
    }

    #[Test]
    public function aggregateHourlyHandlesNullRowsGracefully(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $this->service()->aggregateHourly(new DateTimeImmutable('2026-03-15 10:00:00'), 'empty-site');
        self::addToAssertionCount(1);
    }

    #[Test]
    public function aggregateHourlyUsesCorrectTimeWindow(): void
    {
        /** @var list<array<string, mixed>> $capturedParams */
        $capturedParams = [];

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): Result {
                $capturedParams[] = $params;

                return new Result([]);
            });

        $this->service($connection)->aggregateHourly(new DateTimeImmutable('2026-03-15 23:15:00'), 'site-late');

        self::assertCount(3, $capturedParams);
        self::assertSame('2026-03-15 23:00:00', $capturedParams[0]['from']);
        self::assertSame('2026-03-16 00:00:00', $capturedParams[0]['to']);
        self::assertSame('site-late', $capturedParams[0]['site_id']);
    }

    #[Test]
    public function aggregateHourlyMidnightWindowStartsAtZero(): void
    {
        /** @var list<array<string, mixed>> $capturedParams */
        $capturedParams = [];

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): Result {
                $capturedParams[] = $params;

                return new Result([]);
            });

        $this->service($connection)->aggregateHourly(new DateTimeImmutable('2026-03-15 00:45:00'), 'site-midnight');

        self::assertSame('2026-03-15 00:00:00', $capturedParams[0]['from']);
        self::assertSame('2026-03-15 01:00:00', $capturedParams[0]['to']);
    }

    #[Test]
    public function aggregateDailyQueriesRawDataAndUpsertsStats(): void
    {
        $siteId = 'site-daily';
        $queryCallCount = 0;

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')
            ->willReturnCallback(function () use (&$queryCallCount, $siteId): Result {
                $queryCallCount++;

                return match ($queryCallCount) {
                    1 => new Result([new Row([
                        'site_id' => $siteId,
                        'visitors' => 500,
                        'pageviews' => 2000,
                        'sessions' => 450,
                    ])]),
                    2 => new Result([new Row([
                        'bounce_rate' => 38.2,
                        'avg_duration' => 95.7,
                    ])]),
                    3 => new Result([new Row(['cnt' => 88])]),
                    default => new Result([]),
                };
            });
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->method('execute')->willReturn(0);

        $dailyMock = $this->createMock(DailyStatsRepositoryInterface::class);
        $dailyMock->expects(self::once())
            ->method('upsert')
            ->with(self::callback(function (DailyStats $stats) use ($siteId): bool {
                self::assertSame($siteId, $stats->siteId);
                self::assertSame(500, $stats->visitors);
                self::assertSame(2000, $stats->pageviews);
                self::assertSame(450, $stats->sessions);
                self::assertSame(88, $stats->eventsCount);
                self::assertEqualsWithDelta(38.2, $stats->bounceRate, 0.001);
                self::assertEqualsWithDelta(95.7, $stats->avgDuration, 0.001);

                return true;
            }));

        $this->service($connection, $dailyMock)->aggregateDaily(new DateTimeImmutable('2026-03-15 12:30:00'), $siteId);
    }

    #[Test]
    public function aggregateDailyUsesFullDayRange(): void
    {
        /** @var list<array<string, mixed>> $capturedQueryParams */
        $capturedQueryParams = [];

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedQueryParams): Result {
                $capturedQueryParams[] = $params;

                return new Result([]);
            });
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);

        $this->service($connection)->aggregateDaily(new DateTimeImmutable('2026-03-15 18:45:00'), 'site-range');

        self::assertNotEmpty($capturedQueryParams);
        self::assertSame('2026-03-15 00:00:00', $capturedQueryParams[0]['from']);
        self::assertSame('2026-03-16 00:00:00', $capturedQueryParams[0]['to']);
    }

    #[Test]
    public function aggregateDailyHandlesEmptyResults(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('execute')->willReturn(0);

        $dailyMock = $this->createMock(DailyStatsRepositoryInterface::class);
        $dailyMock->expects(self::once())
            ->method('upsert')
            ->with(self::callback(function (DailyStats $stats): bool {
                self::assertSame(0, $stats->visitors);
                self::assertSame(0, $stats->pageviews);
                self::assertSame(0, $stats->sessions);
                self::assertSame(0, $stats->eventsCount);
                self::assertSame(0.0, $stats->bounceRate);
                self::assertSame(0.0, $stats->avgDuration);

                return true;
            }));

        $this->service($connection, $dailyMock)->aggregateDaily(new DateTimeImmutable('2026-03-15'), 'empty-daily');
    }

    #[Test]
    #[DataProvider('driverUpsertProvider')]
    public function aggregateDailyExecutesBreakdownUpsertsForEachDriver(Driver $driver): void
    {
        /** @var list<string> $executedSqls */
        $executedSqls = [];

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));
        $connection->method('driver')->willReturn($driver);
        $connection->expects(self::exactly(4))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSqls): int {
                $executedSqls[] = $sql;

                return 0;
            });

        $this->service($connection)->aggregateDaily(new DateTimeImmutable('2026-03-15'), 'site-driver');

        self::assertCount(4, $executedSqls);
        self::assertStringContainsString('analytics_daily_pages', $executedSqls[0]);
        self::assertStringContainsString('analytics_daily_referrers', $executedSqls[1]);
        self::assertStringContainsString('analytics_daily_devices', $executedSqls[2]);
        self::assertStringContainsString('analytics_daily_locations', $executedSqls[3]);

        match ($driver) {
            Driver::MySQL => self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $executedSqls[0]),
            Driver::PostgreSQL => self::assertStringContainsString('EXCLUDED', $executedSqls[0]),
            Driver::SQLite => self::assertStringContainsString('excluded', $executedSqls[0]),
        };
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function driverUpsertProvider(): iterable
    {
        yield 'MySQL' => [Driver::MySQL];
        yield 'PostgreSQL' => [Driver::PostgreSQL];
        yield 'SQLite' => [Driver::SQLite];
    }

    #[Test]
    public function aggregateDailySetsDateToStartOfDay(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);

        $dailyMock = $this->createMock(DailyStatsRepositoryInterface::class);
        $dailyMock->expects(self::once())
            ->method('upsert')
            ->with(self::callback(function (DailyStats $stats): bool {
                self::assertSame('2026-03-15 00:00:00', $stats->date->format('Y-m-d H:i:s'));

                return true;
            }));

        $this->service($connection, $dailyMock)->aggregateDaily(new DateTimeImmutable('2026-03-15 18:45:00'), 'site-start');
    }

    #[Test]
    public function aggregateDailyExecutesExactlyFourBreakdownStatements(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::exactly(4))->method('execute')->willReturn(0);

        $this->service($connection)->aggregateDaily(new DateTimeImmutable('2026-03-15'), 'site-tables');
    }

    #[Test]
    public function aggregateDailyQueriesThreeTimesForCounts(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::exactly(3))->method('query')->willReturn(new Result([]));
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('execute')->willReturn(0);

        $this->service($connection)->aggregateDaily(new DateTimeImmutable('2026-03-15'), 'site-count');
    }
}
