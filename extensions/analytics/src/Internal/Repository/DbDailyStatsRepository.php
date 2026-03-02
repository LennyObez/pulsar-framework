<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Repository;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\DailyStats;

#[Internal(reason: 'Raw-DB repository; use DailyStatsRepositoryInterface for public API')]
final readonly class DbDailyStatsRepository implements DailyStatsRepositoryInterface
{
    private const string SQL_UPSERT_PGSQL = <<<'SQL'
        INSERT INTO analytics_stats_daily (site_id, date, visitors, pageviews, sessions, bounce_rate, avg_duration, events_count)
        VALUES (:site_id, :date, :visitors, :pageviews, :sessions, :bounce_rate, :avg_duration, :events_count)
        ON CONFLICT (site_id, date) DO UPDATE SET
            visitors = EXCLUDED.visitors,
            pageviews = EXCLUDED.pageviews,
            sessions = EXCLUDED.sessions,
            bounce_rate = EXCLUDED.bounce_rate,
            avg_duration = EXCLUDED.avg_duration,
            events_count = EXCLUDED.events_count
        SQL;

    private const string SQL_UPSERT_MYSQL = <<<'SQL'
        INSERT INTO analytics_stats_daily (site_id, date, visitors, pageviews, sessions, bounce_rate, avg_duration, events_count)
        VALUES (:site_id, :date, :visitors, :pageviews, :sessions, :bounce_rate, :avg_duration, :events_count)
        ON DUPLICATE KEY UPDATE
            visitors = VALUES(visitors),
            pageviews = VALUES(pageviews),
            sessions = VALUES(sessions),
            bounce_rate = VALUES(bounce_rate),
            avg_duration = VALUES(avg_duration),
            events_count = VALUES(events_count)
        SQL;

    private const string SQL_UPSERT_SQLITE = <<<'SQL'
        INSERT INTO analytics_stats_daily (site_id, date, visitors, pageviews, sessions, bounce_rate, avg_duration, events_count)
        VALUES (:site_id, :date, :visitors, :pageviews, :sessions, :bounce_rate, :avg_duration, :events_count)
        ON CONFLICT (site_id, date) DO UPDATE SET
            visitors = excluded.visitors,
            pageviews = excluded.pageviews,
            sessions = excluded.sessions,
            bounce_rate = excluded.bounce_rate,
            avg_duration = excluded.avg_duration,
            events_count = excluded.events_count
        SQL;

    private const string SQL_FIND_BY_DATE_RANGE = <<<'SQL'
        SELECT * FROM analytics_stats_daily
        WHERE site_id = :site_id AND date >= :from AND date <= :to
        ORDER BY date ASC
        SQL;

    private const string SQL_DELETE_OLDER_THAN = <<<'SQL'
        DELETE FROM analytics_stats_daily WHERE date < :before
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function upsert(DailyStats $stats): void
    {
        $sql = match ($this->connection->driver()) {
            Driver::MySQL => self::SQL_UPSERT_MYSQL,
            Driver::SQLite => self::SQL_UPSERT_SQLITE,
            Driver::PostgreSQL => self::SQL_UPSERT_PGSQL,
        };

        $this->connection->execute($sql, [
            'site_id' => $stats->siteId,
            'date' => $stats->date->format('Y-m-d'),
            'visitors' => $stats->visitors,
            'pageviews' => $stats->pageviews,
            'sessions' => $stats->sessions,
            'bounce_rate' => $stats->bounceRate,
            'avg_duration' => $stats->avgDuration,
            'events_count' => $stats->eventsCount,
        ]);
    }

    public function findByDateRange(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->connection->query(self::SQL_FIND_BY_DATE_RANGE, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ])->map(self::hydrate(...));
    }

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return $this->connection->execute(self::SQL_DELETE_OLDER_THAN, [
            'before' => $before->format('Y-m-d'),
        ]);
    }

    private static function hydrate(Row $row): DailyStats
    {
        return new DailyStats(
            siteId: $row->getString('site_id'),
            date: new DateTimeImmutable($row->getString('date')),
            visitors: $row->getInt('visitors'),
            pageviews: $row->getInt('pageviews'),
            sessions: $row->getInt('sessions'),
            bounceRate: $row->getFloat('bounce_rate'),
            avgDuration: $row->getFloat('avg_duration'),
            eventsCount: $row->getInt('events_count'),
        );
    }
}
