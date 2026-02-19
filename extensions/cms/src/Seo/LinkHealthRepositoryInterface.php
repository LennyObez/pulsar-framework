<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;

/**
 * Persistence interface for link health check records.
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
     * Persist a link health check record.
     */
    public function save(LinkHealthCheck $check): void;

    /**
     * Delete all link health check records for a content item and locale.
     */
    public function deleteByContent(string $contentId, string $locale): void;
}
