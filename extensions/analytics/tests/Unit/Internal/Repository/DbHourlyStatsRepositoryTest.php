<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Repository;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Domain\HourlyStats;
use Pulsar\Extension\Analytics\Internal\Repository\DbHourlyStatsRepository;

final class DbHourlyStatsRepositoryTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private DbHourlyStatsRepository $repo;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::PostgreSQL);
        $this->repo = new DbHourlyStatsRepository($this->connection);
    }

    #[Test]
    public function upsertExecutesSqlWithCorrectBindings(): void
    {
        $stats = new HourlyStats(
            siteId: 'site-1',
            date: new DateTimeImmutable('2026-01-15'),
            hour: 14,
            visitors: 100,
            pageviews: 250,
            sessions: 80,
            bounceRate: 35.5,
            avgDuration: 120.0,
            eventsCount: 10,
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('ON CONFLICT (site_id, date, hour)'),
                self::callback(static function (array $bindings): bool {
                    return $bindings['site_id'] === 'site-1'
                        && $bindings['date'] === '2026-01-15'
                        && $bindings['hour'] === 14
                        && $bindings['visitors'] === 100
                        && $bindings['pageviews'] === 250
                        && $bindings['sessions'] === 80
                        && $bindings['bounce_rate'] === 35.5
                        && $bindings['avg_duration'] === 120.0
                        && $bindings['events_count'] === 10;
                }),
            )
            ->willReturn(1);

        $repo = new DbHourlyStatsRepository($connection);
        $repo->upsert($stats);
    }

    #[Test]
    public function upsertUsesMySqlSyntaxForMysqlDriver(): void
    {
        $stats = new HourlyStats(
            siteId: 'site-1',
            date: new DateTimeImmutable('2026-01-15'),
            hour: 10,
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::stringContains('ON DUPLICATE KEY UPDATE'), self::anything())
            ->willReturn(1);

        $repo = new DbHourlyStatsRepository($connection);
        $repo->upsert($stats);
    }

    #[Test]
    public function upsertUsesSqliteSyntaxForSqliteDriver(): void
    {
        $stats = new HourlyStats(
            siteId: 'site-1',
            date: new DateTimeImmutable('2026-01-15'),
            hour: 10,
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::stringContains('ON CONFLICT (site_id, date, hour)'), self::anything())
            ->willReturn(1);

        $repo = new DbHourlyStatsRepository($connection);
        $repo->upsert($stats);
    }

    #[Test]
    public function findByDateRangeReturnsHydratedStats(): void
    {
        $rows = [
            new Row([
                'site_id' => 'site-1',
                'date' => '2026-01-15',
                'hour' => 10,
                'visitors' => 50,
                'pageviews' => 100,
                'sessions' => 40,
                'bounce_rate' => 30.0,
                'avg_duration' => 90.0,
                'events_count' => 5,
            ]),
            new Row([
                'site_id' => 'site-1',
                'date' => '2026-01-15',
                'hour' => 11,
                'visitors' => 60,
                'pageviews' => 120,
                'sessions' => 50,
                'bounce_rate' => 25.0,
                'avg_duration' => 100.0,
                'events_count' => 8,
            ]),
        ];

        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->method('query')->willReturn($result);

        $repo = new DbHourlyStatsRepository($connection);

        $stats = $repo->findByDateRange(
            'site-1',
            new DateTimeImmutable('2026-01-15'),
            new DateTimeImmutable('2026-01-15'),
        );

        self::assertCount(2, $stats);
        self::assertInstanceOf(HourlyStats::class, $stats[0]);
        self::assertSame('site-1', $stats[0]->siteId);
        self::assertSame(10, $stats[0]->hour);
        self::assertSame(50, $stats[0]->visitors);
        self::assertSame(100, $stats[0]->pageviews);
        self::assertSame(40, $stats[0]->sessions);
        self::assertEqualsWithDelta(30.0, $stats[0]->bounceRate, 0.01);
        self::assertEqualsWithDelta(90.0, $stats[0]->avgDuration, 0.01);
        self::assertSame(5, $stats[0]->eventsCount);

        self::assertSame(11, $stats[1]->hour);
        self::assertSame(60, $stats[1]->visitors);
    }

    #[Test]
    public function findByDateAndHourReturnsHydratedStats(): void
    {
        $row = new Row([
            'site_id' => 'site-1',
            'date' => '2026-01-15',
            'hour' => 14,
            'visitors' => 75,
            'pageviews' => 150,
            'sessions' => 60,
            'bounce_rate' => 28.0,
            'avg_duration' => 110.0,
            'events_count' => 12,
        ]);

        $result = new Result([$row]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->method('query')->willReturn($result);

        $repo = new DbHourlyStatsRepository($connection);

        $stats = $repo->findByDateAndHour('site-1', new DateTimeImmutable('2026-01-15'), 14);

        self::assertNotNull($stats);
        self::assertSame('site-1', $stats->siteId);
        self::assertSame(14, $stats->hour);
        self::assertSame(75, $stats->visitors);
    }

    #[Test]
    public function findByDateAndHourReturnsNullWhenNotFound(): void
    {
        $result = new Result([]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->method('query')->willReturn($result);

        $repo = new DbHourlyStatsRepository($connection);

        $stats = $repo->findByDateAndHour('site-1', new DateTimeImmutable('2026-01-15'), 23);

        self::assertNull($stats);
    }

    #[Test]
    public function deleteOlderThanExecutesSql(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM analytics_stats_hourly'),
                self::callback(static fn(array $b): bool => $b['before'] === '2026-01-10'),
            )
            ->willReturn(5);

        $repo = new DbHourlyStatsRepository($connection);

        $deleted = $repo->deleteOlderThan(new DateTimeImmutable('2026-01-10'));

        self::assertSame(5, $deleted);
    }

    #[Test]
    public function findByDateRangeReturnsEmptyListWhenNoResults(): void
    {
        $result = new Result([]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->method('query')->willReturn($result);

        $repo = new DbHourlyStatsRepository($connection);

        $stats = $repo->findByDateRange(
            'site-1',
            new DateTimeImmutable('2026-01-15'),
            new DateTimeImmutable('2026-01-15'),
        );

        self::assertSame([], $stats);
    }
}
