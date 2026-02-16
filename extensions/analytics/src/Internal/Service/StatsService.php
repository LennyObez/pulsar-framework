<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;

use function round;

/**
 * Query layer for aggregated analytics statistics.
 */
#[Internal(reason: 'Stats query service; use StatsServiceInterface')]
final readonly class StatsService implements StatsServiceInterface
{
    private const string SQL_BREAKDOWN_PAGE = <<<'SQL'
        SELECT pathname AS name, visitors, pageviews
        FROM analytics_daily_pages
        WHERE site_id = :site_id AND date >= :from AND date <= :to
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_BREAKDOWN_REFERRER = <<<'SQL'
        SELECT referrer_source AS name, visitors, pageviews
        FROM analytics_daily_referrers
        WHERE site_id = :site_id AND date >= :from AND date <= :to
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_BREAKDOWN_COUNTRY = <<<'SQL'
        SELECT country_code AS name, visitors, pageviews
        FROM analytics_daily_locations
        WHERE site_id = :site_id AND date >= :from AND date <= :to
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_BREAKDOWN_BROWSER = <<<'SQL'
        SELECT browser AS name, SUM(visitors) AS visitors, 0 AS pageviews
        FROM analytics_daily_devices
        WHERE site_id = :site_id AND date >= :from AND date <= :to
        GROUP BY browser
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_BREAKDOWN_OS = <<<'SQL'
        SELECT os AS name, SUM(visitors) AS visitors, 0 AS pageviews
        FROM analytics_daily_devices
        WHERE site_id = :site_id AND date >= :from AND date <= :to
        GROUP BY os
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_BREAKDOWN_DEVICE = <<<'SQL'
        SELECT device_type AS name, SUM(visitors) AS visitors, 0 AS pageviews
        FROM analytics_daily_devices
        WHERE site_id = :site_id AND date >= :from AND date <= :to
        GROUP BY device_type
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_BREAKDOWN_UTM_SOURCE = <<<'SQL'
        SELECT utm_source AS name, SUM(visitors) AS visitors, SUM(pageviews) AS pageviews
        FROM analytics_daily_referrers
        WHERE site_id = :site_id AND date >= :from AND date <= :to AND utm_source IS NOT NULL AND utm_source != ''
        GROUP BY utm_source
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_BREAKDOWN_UTM_MEDIUM = <<<'SQL'
        SELECT utm_medium AS name, SUM(visitors) AS visitors, SUM(pageviews) AS pageviews
        FROM analytics_daily_referrers
        WHERE site_id = :site_id AND date >= :from AND date <= :to AND utm_medium IS NOT NULL AND utm_medium != ''
        GROUP BY utm_medium
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_BREAKDOWN_UTM_CAMPAIGN = <<<'SQL'
        SELECT utm_campaign AS name, SUM(visitors) AS visitors, SUM(pageviews) AS pageviews
        FROM analytics_daily_referrers
        WHERE site_id = :site_id AND date >= :from AND date <= :to AND utm_campaign IS NOT NULL AND utm_campaign != ''
        GROUP BY utm_campaign
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_REALTIME_VISITORS = <<<'SQL'
        SELECT COUNT(DISTINCT visitor_id) AS current_visitors
        FROM analytics_page_views
        WHERE site_id = :site_id AND created_at > :since
        SQL;

    private const string SQL_REALTIME_PAGES = <<<'SQL'
        SELECT pathname, COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_page_views
        WHERE site_id = :site_id AND created_at > :since
        GROUP BY pathname
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    public function __construct(
        private DailyStatsRepositoryInterface $dailyStatsRepository,
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function getAggregate(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to, array $filters = []): array
    {
        $stats = $this->dailyStatsRepository->findByDateRange($siteId, $from, $to);

        $visitors = 0;
        $pageviews = 0;
        $sessions = 0;
        $bounceSum = 0.0;
        $durationSum = 0.0;
        $eventsCount = 0;
        $count = 0;

        foreach ($stats as $day) {
            $visitors += $day->visitors;
            $pageviews += $day->pageviews;
            $sessions += $day->sessions;
            $bounceSum += $day->bounceRate;
            $durationSum += $day->avgDuration;
            $eventsCount += $day->eventsCount;
            $count++;
        }

        return [
            'visitors' => $visitors,
            'pageviews' => $pageviews,
            'sessions' => $sessions,
            'bounce_rate' => $count > 0 ? round($bounceSum / (float) $count, 1) : 0.0,
            'avg_duration' => $count > 0 ? round($durationSum / (float) $count, 1) : 0.0,
            'events_count' => $eventsCount,
        ];
    }

    #[Override]
    public function getTimeseries(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to, string $metric, string $interval = 'day'): array
    {
        $stats = $this->dailyStatsRepository->findByDateRange($siteId, $from, $to);
        $data = [];

        foreach ($stats as $day) {
            $value = match ($metric) {
                'pageviews' => $day->pageviews,
                'sessions' => $day->sessions,
                'bounce_rate' => $day->bounceRate,
                'avg_duration' => $day->avgDuration,
                'events_count' => $day->eventsCount,
                default => $day->visitors,
            };

            $data[] = [
                'date' => $day->date->format('Y-m-d'),
                'value' => $value,
            ];
        }

        return $data;
    }

    #[Override]
    public function getBreakdown(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to, BreakdownDimension $dimension, int $limit = 10): array
    {
        $sql = match ($dimension) {
            BreakdownDimension::Page => self::SQL_BREAKDOWN_PAGE,
            BreakdownDimension::Referrer => self::SQL_BREAKDOWN_REFERRER,
            BreakdownDimension::Country => self::SQL_BREAKDOWN_COUNTRY,
            BreakdownDimension::Browser => self::SQL_BREAKDOWN_BROWSER,
            BreakdownDimension::Os => self::SQL_BREAKDOWN_OS,
            BreakdownDimension::Device => self::SQL_BREAKDOWN_DEVICE,
            BreakdownDimension::UtmSource => self::SQL_BREAKDOWN_UTM_SOURCE,
            BreakdownDimension::UtmMedium => self::SQL_BREAKDOWN_UTM_MEDIUM,
            BreakdownDimension::UtmCampaign => self::SQL_BREAKDOWN_UTM_CAMPAIGN,
        };

        $result = $this->connection->query($sql, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'limit' => $limit,
        ]);

        $data = [];

        foreach ($result->rows as $row) {
            $data[] = [
                'name' => $row->getString('name'),
                'visitors' => $row->getInt('visitors'),
                'pageviews' => $row->getInt('pageviews'),
            ];
        }

        return $data;
    }

    #[Override]
    public function getAggregateWithComparison(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $previousFrom,
        DateTimeImmutable $previousTo,
        array $filters = [],
    ): array {
        $current = $this->getAggregate($siteId, $from, $to, $filters);
        $previous = $this->getAggregate($siteId, $previousFrom, $previousTo, $filters);

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => [
                'visitors' => self::percentChange($previous['visitors'], $current['visitors']),
                'pageviews' => self::percentChange($previous['pageviews'], $current['pageviews']),
                'sessions' => self::percentChange($previous['sessions'], $current['sessions']),
                'bounce_rate' => self::percentChange($previous['bounce_rate'], $current['bounce_rate']),
                'avg_duration' => self::percentChange($previous['avg_duration'], $current['avg_duration']),
                'events_count' => self::percentChange($previous['events_count'], $current['events_count']),
            ],
        ];
    }

    /**
     * Calculate percentage change between two values.
     */
    private static function percentChange(int|float $previous, int|float $current): float
    {
        if ($previous == 0) {
            return $current == 0 ? 0.0 : 100.0;
        }

        return round((($current - $previous) / (float) $previous) * 100, 1);
    }

    #[Override]
    public function getRealtime(string $siteId): array
    {
        $since = new DateTimeImmutable()->modify('-5 minutes');

        $visitorsResult = $this->connection->query(self::SQL_REALTIME_VISITORS, [
            'site_id' => $siteId,
            'since' => $since->format('Y-m-d H:i:s'),
        ]);

        $visitorsRow = $visitorsResult->first();
        $currentVisitors = $visitorsRow !== null ? $visitorsRow->getInt('current_visitors') : 0;

        $pagesResult = $this->connection->query(self::SQL_REALTIME_PAGES, [
            'site_id' => $siteId,
            'since' => $since->format('Y-m-d H:i:s'),
            'limit' => 5,
        ]);

        $activePages = [];

        foreach ($pagesResult->rows as $row) {
            $activePages[] = [
                'pathname' => $row->getString('pathname'),
                'visitors' => $row->getInt('visitors'),
            ];
        }

        return [
            'current_visitors' => $currentVisitors,
            'active_pages' => $activePages,
        ];
    }
}
