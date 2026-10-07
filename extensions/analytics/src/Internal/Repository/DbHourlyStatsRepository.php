<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Repository;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Domain\HourlyStats;

#[Internal(reason: 'Concrete repository; used internally by AggregationService and StatsService')]
final readonly class DbHourlyStatsRepository
{
    private const string SQL_UPSERT_PGSQL = <<<'SQL'
        INSERT INTO analytics_stats_hourly (site_id, date, hour, visitors, pageviews, sessions, bounce_rate, avg_duration, events_count)
        VALUES (:site_id, :date, :hour, :visitors, :pageviews, :sessions, :bounce_rate, :avg_duration, :events_count)
        ON CONFLICT (site_id, date, hour) DO UPDATE SET
            visitors = EXCLUDED.visitors,
            pageviews = EXCLUDED.pageviews,
            sessions = EXCLUDED.sessions,
            bounce_rate = EXCLUDED.bounce_rate,
            avg_duration = EXCLUDED.avg_duration,
            events_count = EXCLUDED.events_count
        SQL;

    private const string SQL_UPSERT_MYSQL = <<<'SQL'
        INSERT INTO analytics_stats_hourly (site_id, date, hour, visitors, pageviews, sessions, bounce_rate, avg_duration, events_count)
        VALUES (:site_id, :date, :hour, :visitors, :pageviews, :sessions, :bounce_rate, :avg_duration, :events_count)
        ON DUPLICATE KEY UPDATE
            visitors = VALUES(visitors),
            pageviews = VALUES(pageviews),
            sessions = VALUES(sessions),
            bounce_rate = VALUES(bounce_rate),
            avg_duration = VALUES(avg_duration),
            events_count = VALUES(events_count)
        SQL;

    private const string SQL_UPSERT_SQLITE = <<<'SQL'
        INSERT INTO analytics_stats_hourly (site_id, date, hour, visitors, pageviews, sessions, bounce_rate, avg_duration, events_count)
        VALUES (:site_id, :date, :hour, :visitors, :pageviews, :sessions, :bounce_rate, :avg_duration, :events_count)
        ON CONFLICT (site_id, date, hour) DO UPDATE SET
            visitors = excluded.visitors,
            pageviews = excluded.pageviews,
            sessions = excluded.sessions,
            bounce_rate = excluded.bounce_rate,
            avg_duration = excluded.avg_duration,
            events_count = excluded.events_count
        SQL;

    private const string SQL_FIND_BY_DATE_RANGE = <<<'SQL'
        SELECT * FROM analytics_stats_hourly
        WHERE site_id = :site_id AND date >= :from AND date <= :to
        ORDER BY date ASC, hour ASC
        SQL;

    private const string SQL_FIND_BY_DATE_AND_HOUR = <<<'SQL'
        SELECT * FROM analytics_stats_hourly
        WHERE site_id = :site_id AND date = :date AND hour = :hour
        SQL;

    private const string SQL_DELETE_OLDER_THAN = <<<'SQL'
        DELETE FROM analytics_stats_hourly WHERE date < :before
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function upsert(HourlyStats $stats): void
    {
        $sql = match ($this->connection->driver()) {
            Driver::MySQL => self::SQL_UPSERT_MYSQL,
            Driver::SQLite => self::SQL_UPSERT_SQLITE,
            Driver::PostgreSQL => self::SQL_UPSERT_PGSQL,
        };

        $this->connection->execute($sql, [
            'site_id' => $stats->siteId,
            'date' => $stats->date->format('Y-m-d'),
            'hour' => $stats->hour,
            'visitors' => $stats->visitors,
            'pageviews' => $stats->pageviews,
            'sessions' => $stats->sessions,
            'bounce_rate' => $stats->bounceRate,
            'avg_duration' => $stats->avgDuration,
            'events_count' => $stats->eventsCount,
        ]);
    }

    /**
     * @return list<HourlyStats>
     */
    public function findByDateRange(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->connection->query(self::SQL_FIND_BY_DATE_RANGE, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ])->map(self::hydrate(...));
    }

    public function findByDateAndHour(string $siteId, DateTimeImmutable $date, int $hour): ?HourlyStats
    {
        $row = $this->connection->query(self::SQL_FIND_BY_DATE_AND_HOUR, [
            'site_id' => $siteId,
            'date' => $date->format('Y-m-d'),
            'hour' => $hour,
        ])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return $this->connection->execute(self::SQL_DELETE_OLDER_THAN, [
            'before' => $before->format('Y-m-d'),
        ]);
    }

    private static function hydrate(Row $row): HourlyStats
    {
        return new HourlyStats(
            siteId: $row->getString('site_id'),
            date: new DateTimeImmutable($row->getString('date')),
            hour: $row->getInt('hour'),
            visitors: $row->getInt('visitors'),
            pageviews: $row->getInt('pageviews'),
            sessions: $row->getInt('sessions'),
            bounceRate: $row->getFloat('bounce_rate'),
            avgDuration: $row->getFloat('avg_duration'),
            eventsCount: $row->getInt('events_count'),
        );
    }
}
