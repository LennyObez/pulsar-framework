<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\AiConfig;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Config\CommentsConfig;
use Pulsar\Extension\Cms\Config\FormsConfig;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Config\NotificationConfig;
use Pulsar\Extension\Cms\Config\PublishingConfig;
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Config\ThemesConfig;

#[CoversClass(CmsConfig::class)]
final class CmsConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreForNonRegulatedEnvironments(): void
    {
        $config = new CmsConfig();

        self::assertSame('Pulsar CMS', $config->siteName);
        self::assertSame('en', $config->defaultLocale);
        self::assertSame(['en'], $config->supportedLocales);
        self::assertFalse($config->defaultLocaleInUrl);
        self::assertFalse($config->editorialWorkflow);
        self::assertFalse($config->eventSourcing);
        self::assertFalse($config->atomicSnapshots);
        self::assertSame(10, $config->maxHierarchyDepth);
        self::assertNull($config->homepageContentId);
        self::assertInstanceOf(CmsCacheConfig::class, $config->cache);
        self::assertInstanceOf(MediaConfig::class, $config->media);
        self::assertInstanceOf(CommentsConfig::class, $config->comments);
        self::assertInstanceOf(SeoConfig::class, $config->seo);
        self::assertInstanceOf(ThemesConfig::class, $config->themes);
        self::assertInstanceOf(CmsSecurityConfig::class, $config->security);
        self::assertNull($config->commerce);
        self::assertSame(300, $config->httpCacheTtlSeconds);
        self::assertSame(120, $config->publicRateLimitContent);
        self::assertSame(30, $config->publicRateLimitCheckout);
        self::assertFalse($config->apiKeyRequired);
        self::assertInstanceOf(NotificationConfig::class, $config->notifications);
        self::assertInstanceOf(AiConfig::class, $config->ai);
        self::assertInstanceOf(PublishingConfig::class, $config->publishing);
        self::assertInstanceOf(FormsConfig::class, $config->forms);
    }

    #[Test]
    public function fromArrayWithRegulatedConfig(): void
    {
        $config = CmsConfig::fromArray([
            'default_locale' => 'de',
            'supported_locales' => ['de', 'en', 'fr'],
            'default_locale_in_url' => true,
            'editorial_workflow' => true,
            'event_sourcing' => true,
            'atomic_snapshots' => true,
            'max_hierarchy_depth' => 5,
            'homepage_content_id' => 'uuid-123',
            'http_cache_ttl_seconds' => 600,
            'public_rate_limit_content' => 60,
            'public_rate_limit_checkout' => 10,
            'api_key_required' => true,
        ]);

        self::assertSame('de', $config->defaultLocale);
        self::assertSame(['de', 'en', 'fr'], $config->supportedLocales);
        self::assertTrue($config->defaultLocaleInUrl);
        self::assertTrue($config->editorialWorkflow);
        self::assertTrue($config->eventSourcing);
        self::assertTrue($config->atomicSnapshots);
        self::assertSame(5, $config->maxHierarchyDepth);
        self::assertSame('uuid-123', $config->homepageContentId);
        self::assertSame(600, $config->httpCacheTtlSeconds);
        self::assertSame(60, $config->publicRateLimitContent);
        self::assertSame(10, $config->publicRateLimitCheckout);
        self::assertTrue($config->apiKeyRequired);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CmsConfig::fromArray([]);

        self::assertSame('en', $config->defaultLocale);
        self::assertSame(['en'], $config->supportedLocales);
        self::assertFalse($config->editorialWorkflow);
        self::assertNull($config->homepageContentId);
        self::assertNull($config->commerce);
    }

    #[Test]
    public function fromArrayDelegatesNestedConfigSections(): void
    {
        $config = CmsConfig::fromArray([
            'cache' => ['page_cache_ttl_seconds' => 7200],
            'media' => ['disk' => 's3'],
            'comments' => ['enabled' => false],
            'seo' => ['default_robots' => 'noindex'],
            'themes' => ['require_signed_themes' => false],
            'security' => ['ssrf_enabled' => false],
            'ai' => ['enabled' => true, 'provider' => 'anthropic'],
            'notifications' => ['enabled' => true],
            'publishing' => ['rss_enabled' => true],
            'forms' => ['rate_limit_per_hour' => 5],
        ]);

        self::assertSame(7200, $config->cache->pageCacheTtlSeconds);
        self::assertSame('s3', $config->media->disk);
        self::assertFalse($config->comments->enabled);
        self::assertSame('noindex', $config->seo->defaultRobots);
        self::assertFalse($config->themes->requireSignedThemes);
        self::assertFalse($config->security->ssrfEnabled);
        self::assertTrue($config->ai->enabled);
        self::assertSame('anthropic', $config->ai->provider);
        self::assertTrue($config->notifications->enabled);
        self::assertTrue($config->publishing->rssEnabled);
        self::assertSame(5, $config->forms->rateLimitPerHour);
    }

    #[Test]
    public function fromArrayEnablesCommerceWhenKeyPresent(): void
    {
        $config = CmsConfig::fromArray([
            'commerce' => ['currency' => 'EUR'],
        ]);

        self::assertNotNull($config->commerce);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = CmsConfig::fromArray([
            'default_locale' => 42,
            'supported_locales' => 'en',
            'editorial_workflow' => 'yes',
            'max_hierarchy_depth' => '10',
            'homepage_content_id' => 42,
            'http_cache_ttl_seconds' => 'fast',
            'api_key_required' => 1,
        ]);

        self::assertSame('en', $config->defaultLocale);
        self::assertSame(['en'], $config->supportedLocales);
        self::assertFalse($config->editorialWorkflow);
        self::assertSame(10, $config->maxHierarchyDepth);
        self::assertNull($config->homepageContentId);
        self::assertSame(300, $config->httpCacheTtlSeconds);
        self::assertFalse($config->apiKeyRequired);
    }

    #[Test]
    public function fromArrayFiltersNonStringLocales(): void
    {
        $config = CmsConfig::fromArray([
            'supported_locales' => ['en', 42, true, 'de'],
        ]);

        self::assertSame(['en', 'de'], $config->supportedLocales);
    }

    #[Test]
    public function siteNameDefaultsToPulsarCms(): void
    {
        $config = CmsConfig::fromArray([]);

        self::assertSame('Pulsar CMS', $config->siteName);
    }

    #[Test]
    public function siteNameCanBeConfiguredFromArray(): void
    {
        $config = CmsConfig::fromArray([
            'site_name' => 'My Hospital Portal',
        ]);

        self::assertSame('My Hospital Portal', $config->siteName);
    }

    #[Test]
    public function siteNameIgnoresNonStringValues(): void
    {
        $config = CmsConfig::fromArray([
            'site_name' => 42,
        ]);

        self::assertSame('Pulsar CMS', $config->siteName);
    }
}
