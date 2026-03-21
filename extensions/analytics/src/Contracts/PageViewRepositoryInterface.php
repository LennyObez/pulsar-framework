<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\PageView;

/**
 * Persistence interface for page view records.
 * @api
 */
#[Api(since: '1.0.0')]
interface PageViewRepositoryInterface
{
    public function insert(PageView $pageView): void;

    /**
     * @return list<PageView>
     */
    public function findBySite(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 1000): array;

    public function countBySite(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): int;

    /**
     * Count distinct visitors within a recent time window (for realtime).
     */
    public function countRecentVisitors(string $siteId, DateTimeImmutable $since): int;

    /**
     * Get active pages with visitor counts within a recent time window.
     *
     * @return list<array{pathname: string, visitors: int}>
     */
    public function getActivePages(string $siteId, DateTimeImmutable $since, int $limit = 5): array;

    /**
     * Delete page views older than the given date.
     *
     * @return int Number of deleted rows
     */
    public function deleteOlderThan(DateTimeImmutable $before): int;

    /**
     * Find all page views for a specific visitor (GDPR data subject access).
     *
     * @return list<PageView>
     */
    public function findByVisitorId(string $visitorId, int $limit = 10000): array;

    /**
     * Delete all page views for a specific visitor (GDPR right to erasure).
     *
     * @return int Number of deleted rows
     */
    public function deleteByVisitorId(string $visitorId): int;
}
