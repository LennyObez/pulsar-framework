<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Publishing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\PublishingConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Internal\Publishing\RssChannel;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Seo\FeedGeneratorInterface;
use RuntimeException;

#[CoversClass(RssChannel::class)]
final class RssChannelTest extends TestCase
{
    private FeedGeneratorInterface&Stub $feedGenerator;
    private MediaDiskInterface&Stub $disk;

    protected function setUp(): void
    {
        $this->feedGenerator = $this->createStub(FeedGeneratorInterface::class);
        $this->disk = $this->createStub(MediaDiskInterface::class);
    }

    #[Test]
    public function name_returns_rss(): void
    {
        $channel = $this->createChannel();
        self::assertSame('rss', $channel->name());
    }

    #[Test]
    public function is_enabled_when_config_says_so(): void
    {
        $channel = $this->createChannel(rssEnabled: true);
        self::assertTrue($channel->isEnabled());
    }

    #[Test]
    public function is_disabled_by_default(): void
    {
        $channel = $this->createChannel(rssEnabled: false);
        self::assertFalse($channel->isEnabled());
    }

    #[Test]
    public function publish_regenerates_feed_and_writes_to_disk(): void
    {
        $rssXml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel></channel></rss>';
        $this->feedGenerator->method('generateRss')->willReturn($rssXml);

        $channel = $this->createChannel(rssEnabled: true, feedPath: './public/feed.xml');
        $content = $this->createContent();
        $translation = $this->createTranslation();

        $result = $channel->publish($content, $translation);

        self::assertTrue($result->success);
        self::assertSame('rss', $result->channelName);
        self::assertSame('./public/feed.xml', $result->externalUrl);
    }

    #[Test]
    public function publish_returns_failure_on_exception(): void
    {
        $this->feedGenerator->method('generateRss')->willThrowException(
            new RuntimeException('Feed generation failed'),
        );

        $channel = $this->createChannel(rssEnabled: true);
        $content = $this->createContent();
        $translation = $this->createTranslation();

        $result = $channel->publish($content, $translation);

        self::assertFalse($result->success);
        self::assertSame('rss', $result->channelName);
        self::assertSame('Feed generation failed', $result->errorMessage);
    }

    #[Test]
    public function unpublish_regenerates_feed(): void
    {
        $this->feedGenerator->method('generateRss')->willReturn('<rss></rss>');

        $channel = $this->createChannel(rssEnabled: true);
        $content = $this->createContent();

        $result = $channel->unpublish($content);

        self::assertTrue($result->success);
        self::assertSame('rss', $result->channelName);
    }

    private function createChannel(
        bool $rssEnabled = false,
        string $feedPath = './public/feed.xml',
    ): RssChannel {
        return new RssChannel(
            $this->feedGenerator,
            $this->disk,
            new PublishingConfig(rssEnabled: $rssEnabled, rssFeedPath: $feedPath),
            'en',
            'https://example.com',
        );
    }

    private function createContent(): Content
    {
        return Content::create(
            id: '01912345-6789-7abc-8def-0123456789ab',
            contentType: ContentType::Article,
            authorId: '01912345-6789-7abc-8def-0123456789cd',
        );
    }

    private function createTranslation(): ContentTranslation
    {
        return new ContentTranslation(
            id: '01912345-0000-7abc-8def-aaaaaaaaaaaa',
            contentId: '01912345-6789-7abc-8def-0123456789ab',
            locale: 'en',
            title: 'Hello World',
            slugSegment: 'hello-world',
            path: 'blog/hello-world',
            body: '<p>Hello</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Hello',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}
