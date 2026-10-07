<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\PublishingConfig;

#[CoversClass(PublishingConfig::class)]
final class PublishingConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new PublishingConfig();

        self::assertFalse($config->rssEnabled);
        self::assertFalse($config->staticSiteEnabled);
        self::assertSame('./public/static', $config->staticSiteOutputPath);
        self::assertSame('./public/feed.xml', $config->rssFeedPath);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
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

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = PublishingConfig::fromArray([]);

        self::assertFalse($config->rssEnabled);
        self::assertFalse($config->staticSiteEnabled);
        self::assertSame('./public/static', $config->staticSiteOutputPath);
    }

    #[Test]
    public function fromArrayIgnoresNonStringPaths(): void
    {
        $config = PublishingConfig::fromArray([
            'static_site_output_path' => 42,
            'rss_feed_path' => false,
        ]);

        self::assertSame('./public/static', $config->staticSiteOutputPath);
        self::assertSame('./public/feed.xml', $config->rssFeedPath);
    }
}
