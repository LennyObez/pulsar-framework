<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Repository;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\DeviceType;
use Pulsar\Extension\Analytics\Domain\PageView;

#[Internal(reason: 'Raw-DB repository; use PageViewRepositoryInterface for public API')]
final readonly class DbPageViewRepository implements PageViewRepositoryInterface
{
    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO analytics_page_views (
            id, site_id, visitor_id, session_id, pathname,
            referrer_source, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
            country_code, device_type, browser, os, screen_width, is_bounce, created_at
        ) VALUES (
            :id, :site_id, :visitor_id, :session_id, :pathname,
            :referrer_source, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
            :country_code, :device_type, :browser, :os, :screen_width, :is_bounce, :created_at
        )
        SQL;

    private const string SQL_FIND_BY_SITE = <<<'SQL'
        SELECT * FROM analytics_page_views
        WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to
        ORDER BY created_at DESC
        LIMIT :limit
        SQL;

    private const string SQL_COUNT_BY_SITE = <<<'SQL'
        SELECT COUNT(*) AS total FROM analytics_page_views
        WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to
        SQL;

    private const string SQL_COUNT_RECENT_VISITORS = <<<'SQL'
        SELECT COUNT(DISTINCT visitor_id) AS total FROM analytics_page_views
        WHERE site_id = :site_id AND created_at >= :since
        SQL;

    private const string SQL_ACTIVE_PAGES = <<<'SQL'
        SELECT pathname, COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_page_views
        WHERE site_id = :site_id AND created_at >= :since
        GROUP BY pathname
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_FIND_BY_VISITOR = <<<'SQL'
        SELECT * FROM analytics_page_views
        WHERE visitor_id = :visitor_id
        ORDER BY created_at DESC
        LIMIT :limit
        SQL;

    private const string SQL_DELETE_OLDER_THAN = <<<'SQL'
        DELETE FROM analytics_page_views WHERE created_at < :before
        SQL;

    private const string SQL_DELETE_BY_VISITOR = <<<'SQL'
        DELETE FROM analytics_page_views WHERE visitor_id = :visitor_id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function insert(PageView $pageView): void
    {
        $this->connection->execute(self::SQL_INSERT, [
            'id' => $pageView->id,
            'site_id' => $pageView->siteId,
            'visitor_id' => $pageView->visitorId,
            'session_id' => $pageView->sessionId,
            'pathname' => $pageView->pathname,
            'referrer_source' => $pageView->referrerSource,
            'utm_source' => $pageView->utmSource,
            'utm_medium' => $pageView->utmMedium,
            'utm_campaign' => $pageView->utmCampaign,
            'utm_term' => $pageView->utmTerm,
            'utm_content' => $pageView->utmContent,
            'country_code' => $pageView->countryCode,
            'device_type' => $pageView->deviceType->value,
            'browser' => $pageView->browser,
            'os' => $pageView->os,
            'screen_width' => $pageView->screenWidth,
            'is_bounce' => $pageView->isBounce ? 1 : 0,
            'created_at' => $pageView->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findBySite(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 1000): array
    {
        return $this->connection->query(self::SQL_FIND_BY_SITE, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ])->map(self::hydrate(...));
    }

    public function countBySite(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $row = $this->connection->query(self::SQL_COUNT_BY_SITE, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->first();

        return $row?->getInt('total') ?? 0;
    }

    public function countRecentVisitors(string $siteId, DateTimeImmutable $since): int
    {
        $row = $this->connection->query(self::SQL_COUNT_RECENT_VISITORS, [
            'site_id' => $siteId,
            'since' => $since->format('Y-m-d H:i:s'),
        ])->first();

        return $row?->getInt('total') ?? 0;
    }

    public function getActivePages(string $siteId, DateTimeImmutable $since, int $limit = 5): array
    {
        $rows = $this->connection->query(self::SQL_ACTIVE_PAGES, [
            'site_id' => $siteId,
            'since' => $since->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ])->rows;

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'pathname' => $row->getString('pathname'),
                'visitors' => $row->getInt('visitors'),
            ];
        }

        return $result;
    }

    public function findByVisitorId(string $visitorId, int $limit = 10000): array
    {
        return $this->connection->query(self::SQL_FIND_BY_VISITOR, [
            'visitor_id' => $visitorId,
            'limit' => $limit,
        ])->map(self::hydrate(...));
    }

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return $this->connection->execute(self::SQL_DELETE_OLDER_THAN, [
            'before' => $before->format('Y-m-d H:i:s'),
        ]);
    }

    public function deleteByVisitorId(string $visitorId): int
    {
        return $this->connection->execute(self::SQL_DELETE_BY_VISITOR, [
            'visitor_id' => $visitorId,
        ]);
    }

    private static function hydrate(Row $row): PageView
    {
        return new PageView(
            id: $row->getString('id'),
            siteId: $row->getString('site_id'),
            visitorId: $row->getString('visitor_id'),
            sessionId: $row->getString('session_id'),
            pathname: $row->getString('pathname'),
            referrerSource: $row->getString('referrer_source'),
            utmSource: $row->getString('utm_source'),
            utmMedium: $row->getString('utm_medium'),
            utmCampaign: $row->getString('utm_campaign'),
            utmTerm: $row->getString('utm_term'),
            utmContent: $row->getString('utm_content'),
            countryCode: $row->getString('country_code'),
            deviceType: DeviceType::from($row->getString('device_type')),
            browser: $row->getString('browser'),
            os: $row->getString('os'),
            screenWidth: $row->getInt('screen_width'),
            isBounce: $row->getBool('is_bounce'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
