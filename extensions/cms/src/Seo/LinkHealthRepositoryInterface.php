<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;

/**
 * Persistence interface for link health check records.
 *
 * @psalm-api Public binding contract; implemented by DbLinkHealthRepository
 *            and consumed by LinkHealthChecker.
 * @api
 */
#[Api(since: '1.0.0')]
interface LinkHealthRepositoryInterface
{
    /**
     * Find all link health checks for a specific content item and locale.
     *
     * @return list<LinkHealthCheck>
     */
    public function findByContent(string $contentId, string $locale): array;

    /**
     * Find all broken link records, optionally scoped by tenant.
     *
     * @return list<LinkHealthCheck>
     */
    public function findBroken(?string $tenantId = null, int $page = 1, int $perPage = 50): array;

    /**
     * Find link health checks for multiple content items in a single query.
     *
     * @param list<string> $contentIds UUIDv7 content IDs
     * @param string $locale BCP 47 locale code
     * @return array<string, list<LinkHealthCheck>> Keyed by content ID
     */
    public function findByContentIds(array $contentIds, string $locale): array;

    /**
     * Persist a link health check record.
     */
    public function save(LinkHealthCheck $check): void;

    /**
     * Delete all link health check records for a content item and locale.
     */
    public function deleteByContent(string $contentId, string $locale): void;
}
