<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Publishing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\PublishingConfig;

#[CoversClass(PublishingConfig::class)]
final class PublishingConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_sensible(): void
    {
        $config = new PublishingConfig();

        self::assertFalse($config->rssEnabled);
        self::assertFalse($config->staticSiteEnabled);
        self::assertSame('./public/static', $config->staticSiteOutputPath);
        self::assertSame('./public/feed.xml', $config->rssFeedPath);
    }

    #[Test]
    public function from_array_with_defaults(): void
    {
        $config = PublishingConfig::fromArray([]);

        self::assertFalse($config->rssEnabled);
        self::assertFalse($config->staticSiteEnabled);
        self::assertSame('./public/static', $config->staticSiteOutputPath);
        self::assertSame('./public/feed.xml', $config->rssFeedPath);
    }

    #[Test]
    public function from_array_with_custom_values(): void
    {
        $config = PublishingConfig::fromArray([
            'rss_enabled' => true,
            'static_site_enabled' => true,
            'static_site_output_path' => '/var/www/static',
            'rss_feed_path' => '/var/www/public/rss.xml',
        ]);

        self::assertTrue($config->rssEnabled);
        self::assertTrue($config->staticSiteEnabled);
        self::assertSame('/var/www/static', $config->staticSiteOutputPath);
        self::assertSame('/var/www/public/rss.xml', $config->rssFeedPath);
    }

    #[Test]
    public function from_array_casts_string_to_bool(): void
    {
        $config = PublishingConfig::fromArray([
            'rss_enabled' => '1',
            'static_site_enabled' => '0',
        ]);

        self::assertTrue($config->rssEnabled);
        self::assertFalse($config->staticSiteEnabled);
    }

    // ---- Boundary / negative tests ----

    #[Test]
    public function defaults_and_from_array_empty_are_identical(): void
    {
        $direct = new PublishingConfig();
        $fromEmpty = PublishingConfig::fromArray([]);

        self::assertSame($direct->rssEnabled, $fromEmpty->rssEnabled);
        self::assertSame($direct->staticSiteEnabled, $fromEmpty->staticSiteEnabled);
        self::assertSame($direct->staticSiteOutputPath, $fromEmpty->staticSiteOutputPath);
        self::assertSame($direct->rssFeedPath, $fromEmpty->rssFeedPath);
    }

    #[Test]
    public function enabling_rss_does_not_affect_static_site(): void
    {
        $config = PublishingConfig::fromArray(['rss_enabled' => true]);

        self::assertTrue($config->rssEnabled);
        self::assertFalse($config->staticSiteEnabled, 'static site must not be implicitly enabled');
    }

    #[Test]
    public function custom_output_path_is_preserved_verbatim(): void
    {
        $path = '/srv/www/output-2024';
        $config = PublishingConfig::fromArray(['static_site_output_path' => $path]);

        self::assertSame($path, $config->staticSiteOutputPath);
    }

    #[Test]
    public function unknown_keys_in_array_are_ignored(): void
    {
        $config = PublishingConfig::fromArray([
            'rss_enabled' => true,
            'nonexistent_key' => 'should_be_ignored',
        ]);

        self::assertTrue($config->rssEnabled);
        // Default path is unchanged despite unknown key
        self::assertSame('./public/feed.xml', $config->rssFeedPath);
    }
}
