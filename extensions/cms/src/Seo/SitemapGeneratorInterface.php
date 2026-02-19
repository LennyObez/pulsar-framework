<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;

/**
 * Sitemap generation service for XML sitemaps.
 */
#[Api(since: '1.0.0')]
interface SitemapGeneratorInterface
{
    /**
     * Generate the sitemap index XML containing links to per-type sitemaps.
     */
    public function generateIndex(string $baseUrl, ?string $tenantId = null): string;

    /**
     * Generate a sitemap XML for a specific content type.
     */
    public function generateForType(string $contentType, string $baseUrl, int $page = 1, ?string $tenantId = null): string;
}
