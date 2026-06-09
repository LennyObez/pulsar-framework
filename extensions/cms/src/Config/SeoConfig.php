<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * SEO and link health configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by SeoService, SitemapGenerator, and link health checker.
 * @api
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
     * @param array{
     *     default_robots?: string,
     *     title_suffix?: string,
     *     sitemap_changefreq?: array<string, string>,
     *     sitemap_priority?: array<string, float>,
     *     enable_structured_data?: bool,
     *     enable_media_sitemap?: bool,
     *     link_health_check_schedule?: string,
     *     google_site_verification?: string|null,
     *     bing_site_verification?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $rawChangefreq = $data['sitemap_changefreq'] ?? null;
        $rawPriority = $data['sitemap_priority'] ?? null;

        return new self(
            defaultRobots: Coerce::string($data['default_robots'] ?? null, 'index, follow'),
            titleSuffix: Coerce::string($data['title_suffix'] ?? null),
            sitemapChangefreq: is_array($rawChangefreq)
                ? $rawChangefreq
                : ['article' => 'weekly', 'page' => 'monthly'],
            sitemapPriority: is_array($rawPriority)
                ? $rawPriority
                : ['page' => 0.8, 'article' => 0.6],
            enableStructuredData: Coerce::strictBool($data['enable_structured_data'] ?? null, true),
            enableMediaSitemap: Coerce::strictBool($data['enable_media_sitemap'] ?? null, true),
            linkHealthCheckSchedule: Coerce::string($data['link_health_check_schedule'] ?? null, '0 3 * * 0'),
            googleSiteVerification: Coerce::nullableString($data['google_site_verification'] ?? null),
            bingSiteVerification: Coerce::nullableString($data['bing_site_verification'] ?? null),
        );
    }
}
