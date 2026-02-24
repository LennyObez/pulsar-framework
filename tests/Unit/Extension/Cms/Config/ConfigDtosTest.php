<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\AiConfig;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Config\CommentsConfig;
use Pulsar\Extension\Cms\Config\ImageVariantConfig;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Config\NotificationConfig;
use Pulsar\Extension\Cms\Config\PublishingConfig;
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Config\ThemesConfig;

#[CoversClass(AiConfig::class)]
#[CoversClass(CmsCacheConfig::class)]
#[CoversClass(CmsSecurityConfig::class)]
#[CoversClass(CommentsConfig::class)]
#[CoversClass(ImageVariantConfig::class)]
#[CoversClass(MediaConfig::class)]
#[CoversClass(NotificationConfig::class)]
#[CoversClass(PublishingConfig::class)]
#[CoversClass(SeoConfig::class)]
#[CoversClass(ThemesConfig::class)]
final class ConfigDtosTest extends TestCase
{
    // -- AiConfig -------------------------------------------------------------

    #[Test]
    public function aiConfigDefaults(): void
    {
        $config = new AiConfig();

        self::assertFalse($config->enabled);
        self::assertSame('openai', $config->provider);
        self::assertSame('', $config->model);
        self::assertSame('', $config->apiKey);
        self::assertSame('', $config->baseUrl);
    }

    #[Test]
    public function aiConfigFromArray(): void
    {
        $config = AiConfig::fromArray([
            'enabled' => true,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'api_key' => 'sk-ant-test-key',
            'base_url' => 'https://api.anthropic.com',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('anthropic', $config->provider);
        self::assertSame('claude-sonnet-4-6', $config->model);
        self::assertSame('sk-ant-test-key', $config->apiKey);
        self::assertSame('https://api.anthropic.com', $config->baseUrl);
    }

    #[Test]
    public function aiConfigFromEmptyArray(): void
    {
        $config = AiConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('openai', $config->provider);
    }

    // -- CmsCacheConfig -------------------------------------------------------

    #[Test]
    public function cmsCacheConfigDefaults(): void
    {
        $config = new CmsCacheConfig();

        self::assertSame(3600, $config->pageCacheTtlSeconds);
        self::assertTrue($config->stampedeProtection);
        self::assertSame(10, $config->earlyRecomputeBeta);
        self::assertSame(300, $config->staleGracePeriodSeconds);
        self::assertSame(5, $config->lockTimeoutSeconds);
    }

    #[Test]
    public function cmsCacheConfigFromArray(): void
    {
        $config = CmsCacheConfig::fromArray([
            'page_cache_ttl_seconds' => 7200,
            'stampede_protection' => false,
            'early_recompute_beta' => 20,
            'stale_grace_period_seconds' => 600,
            'lock_timeout_seconds' => 10,
        ]);

        self::assertSame(7200, $config->pageCacheTtlSeconds);
        self::assertFalse($config->stampedeProtection);
        self::assertSame(20, $config->earlyRecomputeBeta);
        self::assertSame(600, $config->staleGracePeriodSeconds);
        self::assertSame(10, $config->lockTimeoutSeconds);
    }

    #[Test]
    public function cmsCacheConfigFromEmptyArray(): void
    {
        $config = CmsCacheConfig::fromArray([]);

        self::assertSame(3600, $config->pageCacheTtlSeconds);
        self::assertTrue($config->stampedeProtection);
    }

    // -- CommentsConfig -------------------------------------------------------

    #[Test]
    public function commentsConfigDefaults(): void
    {
        $config = new CommentsConfig();

        self::assertTrue($config->enabled);
        self::assertFalse($config->autoApproveAuthenticated);
        self::assertSame(15, $config->editWindowMinutes);
        self::assertSame(3, $config->maxNestingDepth);
        self::assertSame(5, $config->rateLimitPerMinute);
        self::assertSame(30, $config->rateLimitPerHour);
        self::assertTrue($config->guestCommentsAllowed);
        self::assertFalse($config->requireEmail);
        self::assertSame(10_000, $config->maxBodyLength);
        self::assertSame(3, $config->maxLinksPerComment);
        self::assertSame('website_url', $config->honeypotFieldName);
    }

    #[Test]
    public function commentsConfigFromArray(): void
    {
        $config = CommentsConfig::fromArray([
            'enabled' => false,
            'auto_approve_authenticated' => true,
            'edit_window_minutes' => 30,
            'max_nesting_depth' => 5,
            'rate_limit_per_minute' => 10,
            'rate_limit_per_hour' => 60,
            'guest_comments_allowed' => false,
            'require_email' => true,
            'max_body_length' => 5000,
            'max_links_per_comment' => 1,
            'honeypot_field_name' => 'hp_field',
        ]);

        self::assertFalse($config->enabled);
        self::assertTrue($config->autoApproveAuthenticated);
        self::assertSame(30, $config->editWindowMinutes);
        self::assertFalse($config->guestCommentsAllowed);
        self::assertTrue($config->requireEmail);
        self::assertSame('hp_field', $config->honeypotFieldName);
    }

    #[Test]
    public function commentsConfigFromEmptyArray(): void
    {
        $config = CommentsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertFalse($config->autoApproveAuthenticated);
        self::assertSame(15, $config->editWindowMinutes);
    }

    // -- ImageVariantConfig ---------------------------------------------------

    #[Test]
    public function imageVariantConfigFromArray(): void
    {
        $config = ImageVariantConfig::fromArray([
            'name' => 'thumbnail',
            'max_width' => 300,
            'max_height' => 300,
            'format' => 'webp',
            'quality' => 75,
        ]);

        self::assertSame('thumbnail', $config->name);
        self::assertSame(300, $config->maxWidth);
        self::assertSame(300, $config->maxHeight);
        self::assertSame('webp', $config->format);
        self::assertSame(75, $config->quality);
    }

    #[Test]
    public function imageVariantConfigFromEmptyArray(): void
    {
        $config = ImageVariantConfig::fromArray([]);

        self::assertSame('', $config->name);
        self::assertSame(0, $config->maxWidth);
        self::assertSame(0, $config->maxHeight);
        self::assertSame('original', $config->format);
        self::assertSame(80, $config->quality);
    }

    // -- MediaConfig ----------------------------------------------------------

    #[Test]
    public function mediaConfigDefaults(): void
    {
        $config = new MediaConfig();

        self::assertSame('local', $config->disk);
        self::assertSame(10_485_760, $config->maxUploadSize);
        self::assertContains('image/jpeg', $config->allowedMimeTypes);
        self::assertContains('jpg', $config->allowedExtensions);
        self::assertSame(16384, $config->maxImageWidth);
        self::assertSame(100_000_000, $config->maxPixelCount);
        self::assertFalse($config->preserveExif);
        self::assertSame(80, $config->webpQuality);
        self::assertSame(60, $config->avifQuality);
        self::assertTrue($config->avifEnabled);
        self::assertSame('storage/cms/media', $config->storagePath);
        self::assertSame([], $config->imageVariants);
    }

    #[Test]
    public function mediaConfigFromArray(): void
    {
        $config = MediaConfig::fromArray([
            'disk' => 's3',
            'max_upload_size' => 20_000_000,
            'preserve_exif' => true,
            'avif_enabled' => false,
            'storage_path' => '/custom/media',
            'image_variants' => [
                ['name' => 'thumb', 'max_width' => 150, 'max_height' => 150],
                ['name' => 'medium', 'max_width' => 800, 'max_height' => 600],
            ],
        ]);

        self::assertSame('s3', $config->disk);
        self::assertSame(20_000_000, $config->maxUploadSize);
        self::assertTrue($config->preserveExif);
        self::assertFalse($config->avifEnabled);
        self::assertSame('/custom/media', $config->storagePath);
        self::assertCount(2, $config->imageVariants);
        self::assertSame('thumb', $config->imageVariants[0]->name);
    }

    #[Test]
    public function mediaConfigFromEmptyArray(): void
    {
        $config = MediaConfig::fromArray([]);

        self::assertSame('local', $config->disk);
        self::assertSame([], $config->imageVariants);
    }

    // -- NotificationConfig ---------------------------------------------------

    #[Test]
    public function notificationConfigDefaults(): void
    {
        $config = new NotificationConfig();

        self::assertFalse($config->enabled);
        self::assertSame(['log'], $config->channels);
        self::assertTrue($config->notifyOnPublish);
        self::assertTrue($config->notifyOnReview);
        self::assertTrue($config->notifyOnComment);
    }

    #[Test]
    public function notificationConfigFromArray(): void
    {
        $config = NotificationConfig::fromArray([
            'enabled' => true,
            'channels' => ['email', 'database'],
            'notify_on_publish' => false,
            'notify_on_review' => true,
            'notify_on_comment' => false,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['email', 'database'], $config->channels);
        self::assertFalse($config->notifyOnPublish);
        self::assertTrue($config->notifyOnReview);
        self::assertFalse($config->notifyOnComment);
    }

    // -- PublishingConfig -----------------------------------------------------

    #[Test]
    public function publishingConfigDefaults(): void
    {
        $config = new PublishingConfig();

        self::assertFalse($config->rssEnabled);
        self::assertFalse($config->staticSiteEnabled);
        self::assertSame('./public/static', $config->staticSiteOutputPath);
        self::assertSame('./public/feed.xml', $config->rssFeedPath);
    }

    #[Test]
    public function publishingConfigFromArray(): void
    {
        $config = PublishingConfig::fromArray([
            'rss_enabled' => true,
            'static_site_enabled' => true,
            'static_site_output_path' => '/var/www/static',
            'rss_feed_path' => '/var/www/rss.xml',
        ]);

        self::assertTrue($config->rssEnabled);
        self::assertTrue($config->staticSiteEnabled);
        self::assertSame('/var/www/static', $config->staticSiteOutputPath);
        self::assertSame('/var/www/rss.xml', $config->rssFeedPath);
    }

    // -- SeoConfig ------------------------------------------------------------

    #[Test]
    public function seoConfigDefaults(): void
    {
        $config = new SeoConfig();

        self::assertSame('index, follow', $config->defaultRobots);
        self::assertSame('', $config->titleSuffix);
        self::assertSame('weekly', $config->sitemapChangefreq['article']);
        self::assertSame(0.8, $config->sitemapPriority['page']);
        self::assertTrue($config->enableStructuredData);
        self::assertTrue($config->enableMediaSitemap);
        self::assertSame('0 3 * * 0', $config->linkHealthCheckSchedule);
    }

    #[Test]
    public function seoConfigFromArray(): void
    {
        $config = SeoConfig::fromArray([
            'default_robots' => 'noindex, nofollow',
            'title_suffix' => ' | My Site',
            'enable_structured_data' => false,
            'enable_media_sitemap' => false,
            'link_health_check_schedule' => '0 4 * * 1',
        ]);

        self::assertSame('noindex, nofollow', $config->defaultRobots);
        self::assertSame(' | My Site', $config->titleSuffix);
        self::assertFalse($config->enableStructuredData);
        self::assertFalse($config->enableMediaSitemap);
        self::assertSame('0 4 * * 1', $config->linkHealthCheckSchedule);
    }

    // -- ThemesConfig ---------------------------------------------------------

    #[Test]
    public function themesConfigDefaults(): void
    {
        $config = new ThemesConfig();

        self::assertSame('storage/cms/themes', $config->storagePath);
        self::assertSame('copy', $config->assetDeployMode);
        self::assertTrue($config->requireSignedThemes);
        self::assertSame([], $config->trustedPublicKeys);
        self::assertTrue($config->integrityCheckOnBoot);
        self::assertSame(52_428_800, $config->maxArchiveSize);
        self::assertSame(10_000, $config->maxFileCount);
    }

    #[Test]
    public function themesConfigFromArray(): void
    {
        $config = ThemesConfig::fromArray([
            'storage_path' => '/themes',
            'asset_deploy_mode' => 'symlink',
            'require_signed_themes' => false,
            'trusted_public_keys' => ['key1', 'key2'],
            'integrity_check_on_boot' => false,
            'max_archive_size' => 100_000_000,
            'max_file_count' => 5_000,
        ]);

        self::assertSame('/themes', $config->storagePath);
        self::assertSame('symlink', $config->assetDeployMode);
        self::assertFalse($config->requireSignedThemes);
        self::assertCount(2, $config->trustedPublicKeys);
        self::assertFalse($config->integrityCheckOnBoot);
        self::assertSame(100_000_000, $config->maxArchiveSize);
    }

    // -- CmsSecurityConfig ----------------------------------------------------

    #[Test]
    public function cmsSecurityConfigDefaults(): void
    {
        $config = new CmsSecurityConfig();

        self::assertTrue($config->ssrfEnabled);
        self::assertContains('127.0.0.0/8', $config->blockedIpRanges);
        self::assertSame([], $config->additionalBlockedIps);
        self::assertSame([80, 443], $config->allowedOutboundPorts);
        self::assertSame(3, $config->maxRedirects);
        self::assertSame(5, $config->connectTimeoutSeconds);
        self::assertSame(15, $config->totalTimeoutSeconds);
        self::assertSame(10_485_760, $config->maxResponseBytes);
        self::assertSame('X-Forwarded-For', $config->forwardedForHeader);
        self::assertFalse($config->cloudflareMode);
        self::assertSame(15, $config->stepUpTtlMinutes);
        self::assertFalse($config->requireSignedPlugins);
        self::assertTrue($config->integrityCheckOnBoot);
        self::assertSame(64, $config->ipv6SubnetMask);
    }

    #[Test]
    public function cmsSecurityConfigFromArray(): void
    {
        $config = CmsSecurityConfig::fromArray([
            'ssrf_enabled' => false,
            'max_redirects' => 5,
            'connect_timeout_seconds' => 10,
            'total_timeout_seconds' => 30,
            'max_response_bytes' => 20_000_000,
            'cloudflare_mode' => true,
            'step_up_ttl_minutes' => 30,
            'require_signed_plugins' => true,
            'integrity_check_on_boot' => false,
            'ipv6_subnet_mask' => 48,
            'forwarded_for_header' => 'CF-Connecting-IP',
            'real_ip_header' => 'True-Client-IP',
        ]);

        self::assertFalse($config->ssrfEnabled);
        self::assertSame(5, $config->maxRedirects);
        self::assertSame(30, $config->totalTimeoutSeconds);
        self::assertTrue($config->cloudflareMode);
        self::assertSame(30, $config->stepUpTtlMinutes);
        self::assertTrue($config->requireSignedPlugins);
        self::assertFalse($config->integrityCheckOnBoot);
        self::assertSame(48, $config->ipv6SubnetMask);
        self::assertSame('CF-Connecting-IP', $config->forwardedForHeader);
    }

    #[Test]
    public function cmsSecurityConfigFromEmptyArray(): void
    {
        $config = CmsSecurityConfig::fromArray([]);

        self::assertTrue($config->ssrfEnabled);
        self::assertSame(3, $config->maxRedirects);
        self::assertFalse($config->cloudflareMode);
    }
}
