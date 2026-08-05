<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Dialect\Dialects;
use Pulsar\Extension\Analytics\Contracts\AggregationServiceInterface;
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
#[Internal(reason: 'Aggregation pipeline; used by scheduled jobs')]
final readonly class AggregationService implements AggregationServiceInterface
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

    private const string SQL_DAILY_PAGES = <<<'SQL'
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
        SQL;

    // --- Daily breakdown: Referrers ---

    private const string SQL_DAILY_REFERRERS = <<<'SQL'
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
        SQL;

    // --- Daily breakdown: Devices ---

    private const string SQL_DAILY_DEVICES = <<<'SQL'
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
        SQL;

    // --- Daily breakdown: Locations ---

    private const string SQL_DAILY_LOCATIONS = <<<'SQL'
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
        SQL;

    /**
     * The conflict target and the columns re-aggregation overwrites, per breakdown.
     *
     * These are the only parts of an upsert that differ between breakdowns; the parts
     * that differ between ENGINES are the dialect's business, not this service's. Twelve
     * hand-written statements — four breakdowns times three engines — collapsed to four
     * once the dialect was asked to compile the conflict clause instead of each variant
     * being maintained by hand. A fifth engine adds nothing here.
     *
     * @var array<string, array{list<string>, list<string>}>
     */
    private const array UPSERT_KEYS = [
        'pages' => [['site_id', 'date', 'pathname'], ['visitors', 'pageviews']],
        'referrers' => [['site_id', 'date', 'referrer_source'], ['visitors', 'pageviews']],
        'devices' => [['site_id', 'date', 'device_type', 'browser', 'os'], ['visitors']],
        'locations' => [['site_id', 'date', 'country_code', 'region'], ['visitors', 'pageviews']],
    ];


    public function __construct(
        private DailyStatsRepositoryInterface $dailyStatsRepository,
        private DbHourlyStatsRepository $hourlyStatsRepository,
        private ConnectionInterface $connection,
    ) {}

    #[Override]
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

    #[Override]
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
     * The upsert for a breakdown table, in the SQL this connection's engine accepts.
     *
     * This method used to select between twelve statements written by hand, one per
     * breakdown per engine, and adding an engine meant writing four more. It now states
     * the aggregation once and asks the dialect how that engine spells a conflict clause,
     * so what varies by engine is expressed in the one place that knows about engines.
     */
    private function upsertSql(string $table): string
    {
        $insert = match ($table) {
            'pages' => self::SQL_DAILY_PAGES,
            'referrers' => self::SQL_DAILY_REFERRERS,
            'devices' => self::SQL_DAILY_DEVICES,
            'locations' => self::SQL_DAILY_LOCATIONS,
            default => throw new InvalidArgumentException("Unknown aggregation table: $table"),
        };

        [$conflictColumns, $updateColumns] = self::UPSERT_KEYS[$table];

        return Dialects::for($this->connection->driver())
            ->compileUpsert($insert, $conflictColumns, $updateColumns);
    }
}
