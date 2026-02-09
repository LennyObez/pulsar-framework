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
}
