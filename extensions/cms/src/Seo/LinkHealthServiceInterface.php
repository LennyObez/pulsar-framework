<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\Content;

/**
 * Service for checking and reporting on link health across content.
 *
 * @psalm-api Public binding contract; implemented by LinkHealthChecker and
 *            consumed by scheduled jobs and Studio panels.
 */
#[Api(since: '1.0.0')]
interface LinkHealthServiceInterface
{
    /**
     * Run a full link health check across all published content.
     *
     * @return list<LinkHealthCheck>
     */
    public function checkAll(?string $tenantId = null): array;

    /**
     * Check all links within a specific content item.
     *
     * @return list<LinkHealthCheck>
     */
    public function checkContent(Content $content, string $locale): array;

    /**
     * Retrieve all known broken links.
     *
     * @return list<LinkHealthCheck>
     */
    public function getBrokenLinks(?string $tenantId = null, int $page = 1, int $perPage = 50): array;

    /**
     * Find published content that has no inbound internal links (orphan pages).
     *
     * @return list<Content>
     */
    public function getOrphanContent(?string $tenantId = null, int $page = 1, int $perPage = 50): array;
}
