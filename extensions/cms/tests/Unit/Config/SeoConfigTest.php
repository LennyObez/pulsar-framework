<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\SeoConfig;

#[CoversClass(SeoConfig::class)]
final class SeoConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreReasonable(): void
    {
        $config = new SeoConfig();

        self::assertSame('index, follow', $config->defaultRobots);
        self::assertSame('', $config->titleSuffix);
        self::assertSame(['article' => 'weekly', 'page' => 'monthly'], $config->sitemapChangefreq);
        self::assertSame(['page' => 0.8, 'article' => 0.6], $config->sitemapPriority);
        self::assertTrue($config->enableStructuredData);
        self::assertTrue($config->enableMediaSitemap);
        self::assertSame('0 3 * * 0', $config->linkHealthCheckSchedule);
        self::assertNull($config->googleSiteVerification);
        self::assertNull($config->bingSiteVerification);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = SeoConfig::fromArray([
            'default_robots' => 'noindex, nofollow',
            'title_suffix' => ' | My Site',
            'sitemap_changefreq' => ['post' => 'daily', 'product' => 'weekly'],
            'sitemap_priority' => ['post' => 0.9, 'product' => 0.7],
            'enable_structured_data' => false,
            'enable_media_sitemap' => false,
            'link_health_check_schedule' => '0 2 * * 1',
            'google_site_verification' => 'google_test_code',
            'bing_site_verification' => 'bing_test_code',
        ]);

        self::assertSame('noindex, nofollow', $config->defaultRobots);
        self::assertSame(' | My Site', $config->titleSuffix);
        self::assertSame(['post' => 'daily', 'product' => 'weekly'], $config->sitemapChangefreq);
        self::assertSame(['post' => 0.9, 'product' => 0.7], $config->sitemapPriority);
        self::assertFalse($config->enableStructuredData);
        self::assertFalse($config->enableMediaSitemap);
        self::assertSame('0 2 * * 1', $config->linkHealthCheckSchedule);
        self::assertSame('google_test_code', $config->googleSiteVerification);
        self::assertSame('bing_test_code', $config->bingSiteVerification);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = SeoConfig::fromArray([]);

        self::assertSame('index, follow', $config->defaultRobots);
        self::assertSame(['article' => 'weekly', 'page' => 'monthly'], $config->sitemapChangefreq);
        self::assertTrue($config->enableStructuredData);
        self::assertNull($config->googleSiteVerification);
        self::assertNull($config->bingSiteVerification);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = SeoConfig::fromArray([
            'default_robots' => 42,
            'title_suffix' => false,
            'sitemap_changefreq' => 'weekly',
            'sitemap_priority' => 0.5,
            'enable_structured_data' => 'yes',
            'link_health_check_schedule' => 123,
            'google_site_verification' => 12345,
            'bing_site_verification' => true,
        ]);

        self::assertSame('index, follow', $config->defaultRobots);
        self::assertSame('', $config->titleSuffix);
        self::assertSame(['article' => 'weekly', 'page' => 'monthly'], $config->sitemapChangefreq);
        self::assertSame(['page' => 0.8, 'article' => 0.6], $config->sitemapPriority);
        self::assertTrue($config->enableStructuredData);
        self::assertSame('0 3 * * 0', $config->linkHealthCheckSchedule);
        self::assertNull($config->googleSiteVerification);
        self::assertNull($config->bingSiteVerification);
    }
}
