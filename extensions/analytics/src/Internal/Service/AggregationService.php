<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\DailyStats;
use Pulsar\Extension\Analytics\Domain\HourlyStats;
use Pulsar\Extension\Analytics\Internal\Repository\DbHourlyStatsRepository;

/**
 * Rolls up raw page views and events into hourly and daily aggregated stats.
 *
 * Uses driver-specific SQL for upsert operations (MySQL uses ON DUPLICATE KEY
 * UPDATE, PostgreSQL uses ON CONFLICT ... DO UPDATE with EXCLUDED, SQLite uses
 * ON CONFLICT ... DO UPDATE with lowercase excluded).
 */
#[Internal(reason: 'Aggregation pipeline — used by scheduled jobs')]
final readonly class AggregationService
{
    private const string SQL_HOURLY_AGGREGATE = <<<'SQL'
        SELECT
            site_id,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews,
            COUNT(DISTINCT session_id) AS sessions
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        GROUP BY site_id
        SQL;

    private const string SQL_HOURLY_EVENTS_COUNT = <<<'SQL'
        SELECT COUNT(*) AS cnt
        FROM analytics_events
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        SQL;

    /**
     * Daily aggregate queries raw page_views for true distinct counts over the full day.
     * Unlike summing hourly distinct counts (which double-counts cross-hour visitors),
     * this produces the mathematically correct daily distinct visitor count.
     */
    private const string SQL_DAILY_AGGREGATE = <<<'SQL'
        SELECT
            site_id,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews,
            COUNT(DISTINCT session_id) AS sessions
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        GROUP BY site_id
        SQL;

    /**
     * Daily bounce rate and avg duration from raw sessions (weighted properly).
     * Bounce rate = total bounced sessions / total sessions (not avg of hourly %).
     * Avg duration = total duration / total sessions (not avg of hourly avgs).
     */
    private const string SQL_DAILY_BOUNCE_RATE = <<<'SQL'
        SELECT
            CASE WHEN COUNT(*) = 0 THEN 0
                 ELSE SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*)
            END AS bounce_rate,
            CASE WHEN COUNT(*) = 0 THEN 0
                 ELSE AVG(duration_seconds)
            END AS avg_duration
        FROM analytics_sessions
        WHERE started_at >= :from AND started_at < :to
            AND site_id = :site_id
        SQL;

    private const string SQL_HOURLY_BOUNCE_RATE = <<<'SQL'
        SELECT
            CASE WHEN COUNT(*) = 0 THEN 0
                 ELSE SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*)
            END AS bounce_rate,
            CASE WHEN COUNT(*) = 0 THEN 0
                 ELSE AVG(duration_seconds)
            END AS avg_duration
        FROM analytics_sessions
        WHERE started_at >= :from AND started_at < :to
            AND site_id = :site_id
        SQL;

    // --- Daily breakdown: Pages ---

    private const string SQL_DAILY_PAGES_PGSQL = <<<'SQL'
        INSERT INTO analytics_daily_pages (site_id, date, pathname, visitors, pageviews, entries, exits, avg_time_on_page)
        SELECT
            site_id,
            DATE(created_at) AS date,
            pathname,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews,
            0 AS entries,
            0 AS exits,
            0 AS avg_time_on_page
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        GROUP BY site_id, DATE(created_at), pathname
        ON CONFLICT (site_id, date, pathname) DO UPDATE SET
            visitors = EXCLUDED.visitors,
            pageviews = EXCLUDED.pageviews
        SQL;

    private const string SQL_DAILY_PAGES_MYSQL = <<<'SQL'
        INSERT INTO analytics_daily_pages (site_id, date, pathname, visitors, pageviews, entries, exits, avg_time_on_page)
        SELECT
            site_id,
            DATE(created_at) AS date,
            pathname,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews,
            0 AS entries,
            0 AS exits,
            0 AS avg_time_on_page
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        GROUP BY site_id, DATE(created_at), pathname
        ON DUPLICATE KEY UPDATE
            visitors = VALUES(visitors),
            pageviews = VALUES(pageviews)
        SQL;

    private const string SQL_DAILY_PAGES_SQLITE = <<<'SQL'
        INSERT INTO analytics_daily_pages (site_id, date, pathname, visitors, pageviews, entries, exits, avg_time_on_page)
        SELECT
            site_id,
            DATE(created_at) AS date,
            pathname,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews,
            0 AS entries,
            0 AS exits,
            0 AS avg_time_on_page
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        GROUP BY site_id, DATE(created_at), pathname
        ON CONFLICT (site_id, date, pathname) DO UPDATE SET
            visitors = excluded.visitors,
            pageviews = excluded.pageviews
        SQL;

    // --- Daily breakdown: Referrers ---

    private const string SQL_DAILY_REFERRERS_PGSQL = <<<'SQL'
        INSERT INTO analytics_daily_referrers (site_id, date, referrer_source, visitors, pageviews)
        SELECT
            site_id,
            DATE(created_at) AS date,
            referrer_source,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
            AND referrer_source != ''
        GROUP BY site_id, DATE(created_at), referrer_source
        ON CONFLICT (site_id, date, referrer_source) DO UPDATE SET
            visitors = EXCLUDED.visitors,
            pageviews = EXCLUDED.pageviews
        SQL;

    private const string SQL_DAILY_REFERRERS_MYSQL = <<<'SQL'
        INSERT INTO analytics_daily_referrers (site_id, date, referrer_source, visitors, pageviews)
        SELECT
            site_id,
            DATE(created_at) AS date,
            referrer_source,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
            AND referrer_source != ''
        GROUP BY site_id, DATE(created_at), referrer_source
        ON DUPLICATE KEY UPDATE
            visitors = VALUES(visitors),
            pageviews = VALUES(pageviews)
        SQL;

    private const string SQL_DAILY_REFERRERS_SQLITE = <<<'SQL'
        INSERT INTO analytics_daily_referrers (site_id, date, referrer_source, visitors, pageviews)
        SELECT
            site_id,
            DATE(created_at) AS date,
            referrer_source,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
            AND referrer_source != ''
        GROUP BY site_id, DATE(created_at), referrer_source
        ON CONFLICT (site_id, date, referrer_source) DO UPDATE SET
            visitors = excluded.visitors,
            pageviews = excluded.pageviews
        SQL;

    // --- Daily breakdown: Devices ---

    private const string SQL_DAILY_DEVICES_PGSQL = <<<'SQL'
        INSERT INTO analytics_daily_devices (site_id, date, device_type, browser, os, visitors)
        SELECT
            site_id,
            DATE(created_at) AS date,
            device_type,
            browser,
            os,
            COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        GROUP BY site_id, DATE(created_at), device_type, browser, os
        ON CONFLICT (site_id, date, device_type, browser, os) DO UPDATE SET
            visitors = EXCLUDED.visitors
        SQL;

    private const string SQL_DAILY_DEVICES_MYSQL = <<<'SQL'
        INSERT INTO analytics_daily_devices (site_id, date, device_type, browser, os, visitors)
        SELECT
            site_id,
            DATE(created_at) AS date,
            device_type,
            browser,
            os,
            COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        GROUP BY site_id, DATE(created_at), device_type, browser, os
        ON DUPLICATE KEY UPDATE
            visitors = VALUES(visitors)
        SQL;

    private const string SQL_DAILY_DEVICES_SQLITE = <<<'SQL'
        INSERT INTO analytics_daily_devices (site_id, date, device_type, browser, os, visitors)
        SELECT
            site_id,
            DATE(created_at) AS date,
            device_type,
            browser,
            os,
            COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
        GROUP BY site_id, DATE(created_at), device_type, browser, os
        ON CONFLICT (site_id, date, device_type, browser, os) DO UPDATE SET
            visitors = excluded.visitors
        SQL;

    // --- Daily breakdown: Locations ---

    private const string SQL_DAILY_LOCATIONS_PGSQL = <<<'SQL'
        INSERT INTO analytics_daily_locations (site_id, date, country_code, region, visitors, pageviews)
        SELECT
            site_id,
            DATE(created_at) AS date,
            country_code,
            '' AS region,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
            AND country_code != ''
        GROUP BY site_id, DATE(created_at), country_code
        ON CONFLICT (site_id, date, country_code, region) DO UPDATE SET
            visitors = EXCLUDED.visitors,
            pageviews = EXCLUDED.pageviews
        SQL;

    private const string SQL_DAILY_LOCATIONS_MYSQL = <<<'SQL'
        INSERT INTO analytics_daily_locations (site_id, date, country_code, region, visitors, pageviews)
        SELECT
            site_id,
            DATE(created_at) AS date,
            country_code,
            '' AS region,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
            AND country_code != ''
        GROUP BY site_id, DATE(created_at), country_code
        ON DUPLICATE KEY UPDATE
            visitors = VALUES(visitors),
            pageviews = VALUES(pageviews)
        SQL;

    private const string SQL_DAILY_LOCATIONS_SQLITE = <<<'SQL'
        INSERT INTO analytics_daily_locations (site_id, date, country_code, region, visitors, pageviews)
        SELECT
            site_id,
            DATE(created_at) AS date,
            country_code,
            '' AS region,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(*) AS pageviews
        FROM analytics_page_views
        WHERE created_at >= :from AND created_at < :to
            AND site_id = :site_id
            AND country_code != ''
        GROUP BY site_id, DATE(created_at), country_code
        ON CONFLICT (site_id, date, country_code, region) DO UPDATE SET
            visitors = excluded.visitors,
            pageviews = excluded.pageviews
        SQL;

    public function __construct(
        private DailyStatsRepositoryInterface $dailyStatsRepository,
        private DbHourlyStatsRepository $hourlyStatsRepository,
        private ConnectionInterface $connection,
    ) {}

    public function aggregateHourly(DateTimeImmutable $hour, string $siteId): void
    {
        $from = $hour->setTime((int) $hour->format('G'), 0);
        $to = $from->modify('+1 hour');

        $result = $this->connection->query(self::SQL_HOURLY_AGGREGATE, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'site_id' => $siteId,
        ]);

        $row = $result->first();

        $eventsResult = $this->connection->query(self::SQL_HOURLY_EVENTS_COUNT, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'site_id' => $siteId,
        ]);

        $eventsRow = $eventsResult->first();
        $eventsCount = $eventsRow !== null ? $eventsRow->getInt('cnt') : 0;

        $bounceResult = $this->connection->query(self::SQL_HOURLY_BOUNCE_RATE, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'site_id' => $siteId,
        ]);

        $bounceRow = $bounceResult->first();

        $stats = new HourlyStats(
            siteId: $siteId,
            date: $from,
            hour: (int) $from->format('G'),
            visitors: $row !== null ? $row->getInt('visitors') : 0,
            pageviews: $row !== null ? $row->getInt('pageviews') : 0,
            sessions: $row !== null ? $row->getInt('sessions') : 0,
            bounceRate: $bounceRow !== null ? $bounceRow->getFloat('bounce_rate') : 0.0,
            avgDuration: $bounceRow !== null ? $bounceRow->getFloat('avg_duration') : 0.0,
            eventsCount: $eventsCount,
        );

        $this->hourlyStatsRepository->upsert($stats);
    }

    public function aggregateDaily(DateTimeImmutable $date, string $siteId): void
    {
        $from = $date->setTime(0, 0);
        $to = $from->modify('+1 day');

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'site_id' => $siteId,
        ];

        // Query raw page_views for true daily distinct visitors, total pageviews, sessions
        $pvResult = $this->connection->query(self::SQL_DAILY_AGGREGATE, $params);
        $pvRow = $pvResult->first();

        // Query raw sessions for true daily bounce rate (weighted) and avg duration (weighted)
        $sessResult = $this->connection->query(self::SQL_DAILY_BOUNCE_RATE, $params);
        $sessRow = $sessResult->first();

        // Query raw events for true daily count
        $eventsResult = $this->connection->query(self::SQL_HOURLY_EVENTS_COUNT, $params);
        $eventsRow = $eventsResult->first();

        $daily = new DailyStats(
            siteId: $siteId,
            date: $from,
            visitors: $pvRow !== null ? $pvRow->getInt('visitors') : 0,
            pageviews: $pvRow !== null ? $pvRow->getInt('pageviews') : 0,
            sessions: $pvRow !== null ? $pvRow->getInt('sessions') : 0,
            bounceRate: $sessRow !== null ? $sessRow->getFloat('bounce_rate') : 0.0,
            avgDuration: $sessRow !== null ? $sessRow->getFloat('avg_duration') : 0.0,
            eventsCount: $eventsRow !== null ? $eventsRow->getInt('cnt') : 0,
        );

        $this->dailyStatsRepository->upsert($daily);

        // Populate breakdown tables with driver-specific upsert SQL
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'site_id' => $siteId,
        ];

        $this->connection->execute($this->upsertSql('pages'), $params);
        $this->connection->execute($this->upsertSql('referrers'), $params);
        $this->connection->execute($this->upsertSql('devices'), $params);
        $this->connection->execute($this->upsertSql('locations'), $params);
    }

    /**
     * Select the driver-appropriate upsert SQL for a breakdown table.
     */
    private function upsertSql(string $table): string
    {
        $driver = $this->connection->driver();

        return match ($table) {
            'pages' => match ($driver) {
                Driver::MySQL => self::SQL_DAILY_PAGES_MYSQL,
                Driver::SQLite => self::SQL_DAILY_PAGES_SQLITE,
                Driver::PostgreSQL => self::SQL_DAILY_PAGES_PGSQL,
            },
            'referrers' => match ($driver) {
                Driver::MySQL => self::SQL_DAILY_REFERRERS_MYSQL,
                Driver::SQLite => self::SQL_DAILY_REFERRERS_SQLITE,
                Driver::PostgreSQL => self::SQL_DAILY_REFERRERS_PGSQL,
            },
            'devices' => match ($driver) {
                Driver::MySQL => self::SQL_DAILY_DEVICES_MYSQL,
                Driver::SQLite => self::SQL_DAILY_DEVICES_SQLITE,
                Driver::PostgreSQL => self::SQL_DAILY_DEVICES_PGSQL,
            },
            'locations' => match ($driver) {
                Driver::MySQL => self::SQL_DAILY_LOCATIONS_MYSQL,
                Driver::SQLite => self::SQL_DAILY_LOCATIONS_SQLITE,
                Driver::PostgreSQL => self::SQL_DAILY_LOCATIONS_PGSQL,
            },
        };
    }
}
