<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_string;

/**
 * SEO and link health configuration.
 */
#[Api(since: '1.0.0')]
final readonly class SeoConfig
{
    /**
     * @param string $defaultRobots Default robots meta tag value
     * @param string $titleSuffix Suffix appended to every page title
     * @param array<string, string> $sitemapChangefreq Per-content-type sitemap changefreq values
     * @param array<string, float> $sitemapPriority Per-content-type sitemap priority values
     * @param bool $enableStructuredData Whether to generate JSON-LD structured data
     * @param bool $enableMediaSitemap Whether to include media in sitemaps
     * @param string $linkHealthCheckSchedule Cron expression for link health check runs
     * @param string|null $googleSiteVerification Google Search Console verification code
     * @param string|null $bingSiteVerification Bing Webmaster Tools verification code
     */
    public function __construct(
        public string $defaultRobots = 'index, follow',
        public string $titleSuffix = '',
        public array $sitemapChangefreq = ['article' => 'weekly', 'page' => 'monthly'],
        public array $sitemapPriority = ['page' => 0.8, 'article' => 0.6],
        public bool $enableStructuredData = true,
        public bool $enableMediaSitemap = true,
        public string $linkHealthCheckSchedule = '0 3 * * 0',
        public ?string $googleSiteVerification = null,
        public ?string $bingSiteVerification = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $changefreq */
        $changefreq = is_array($data['sitemap_changefreq'] ?? null) ? $data['sitemap_changefreq'] : ['article' => 'weekly', 'page' => 'monthly'];
        /** @var array<string, float> $priority */
        $priority = is_array($data['sitemap_priority'] ?? null) ? $data['sitemap_priority'] : ['page' => 0.8, 'article' => 0.6];

        return new self(
            defaultRobots: is_string($data['default_robots'] ?? null) ? $data['default_robots'] : 'index, follow',
            titleSuffix: is_string($data['title_suffix'] ?? null) ? $data['title_suffix'] : '',
            sitemapChangefreq: $changefreq,
            sitemapPriority: $priority,
            enableStructuredData: is_bool($data['enable_structured_data'] ?? null) ? $data['enable_structured_data'] : true,
            enableMediaSitemap: is_bool($data['enable_media_sitemap'] ?? null) ? $data['enable_media_sitemap'] : true,
            linkHealthCheckSchedule: is_string($data['link_health_check_schedule'] ?? null) ? $data['link_health_check_schedule'] : '0 3 * * 0',
            googleSiteVerification: is_string($data['google_site_verification'] ?? null) ? $data['google_site_verification'] : null,
            bingSiteVerification: is_string($data['bing_site_verification'] ?? null) ? $data['bing_site_verification'] : null,
        );
    }
}
