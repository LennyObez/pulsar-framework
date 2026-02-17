<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Feed\FeedGenerator;
use Pulsar\Extension\Forum\Feed\FeedItem;

final class FeedGeneratorTest extends TestCase
{
    private FeedGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FeedGenerator('https://forum.example.com', 'Test Forum');
    }

    #[Test]
    public function generateRssProducesValidXml(): void
    {
        $items = [
            new FeedItem(
                title: 'First Thread',
                url: 'https://forum.example.com/t/first-thread',
                description: 'This is the first thread.',
                publishedAt: new DateTimeImmutable('2026-03-15 10:00:00'),
                author: 'john@example.com',
                categories: ['General', 'Help'],
            ),
        ];

        $rss = $this->generator->generateRss('Latest Threads', 'All threads', $items);

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $rss);
        self::assertStringContainsString('<rss version="2.0"', $rss);
        self::assertStringContainsString('<title>Latest Threads</title>', $rss);
        self::assertStringContainsString('<title>First Thread</title>', $rss);
        self::assertStringContainsString('<link>https://forum.example.com/t/first-thread</link>', $rss);
        self::assertStringContainsString('<author>john@example.com</author>', $rss);
        self::assertStringContainsString('<category>General</category>', $rss);
        self::assertStringContainsString('<category>Help</category>', $rss);
        self::assertStringContainsString('<generator>Pulsar Test Forum</generator>', $rss);
    }

    #[Test]
    public function generateRssHandlesEmptyItems(): void
    {
        $rss = $this->generator->generateRss('Empty Feed', 'No items', []);

        self::assertStringContainsString('<title>Empty Feed</title>', $rss);
        self::assertStringNotContainsString('<item>', $rss);
    }

    #[Test]
    public function generateRssEscapesXmlEntities(): void
    {
        $items = [
            new FeedItem(
                title: 'Thread with <html> & "quotes"',
                url: 'https://example.com/t/1?a=1&b=2',
                description: 'Content with <script>alert("xss")</script>',
                publishedAt: new DateTimeImmutable(),
            ),
        ];

        $rss = $this->generator->generateRss('Test', 'Test', $items);

        self::assertStringContainsString('&lt;html&gt;', $rss);
        self::assertStringContainsString('&amp;', $rss);
        self::assertStringNotContainsString('<script>', $rss);
    }

    #[Test]
    public function generateRssIncludesGuid(): void
    {
        $items = [
            new FeedItem(
                title: 'Test',
                url: 'https://example.com/t/test',
                description: 'Test description',
                publishedAt: new DateTimeImmutable(),
            ),
        ];

        $rss = $this->generator->generateRss('Feed', 'Desc', $items);

        self::assertStringContainsString('<guid isPermaLink="true">https://example.com/t/test</guid>', $rss);
    }

    #[Test]
    public function generateRssOmitsAuthorWhenEmpty(): void
    {
        $items = [
            new FeedItem(
                title: 'No Author',
                url: 'https://example.com/t/1',
                description: 'Content',
                publishedAt: new DateTimeImmutable(),
            ),
        ];

        $rss = $this->generator->generateRss('Feed', 'Desc', $items);

        self::assertStringNotContainsString('<author>', $rss);
    }

    #[Test]
    public function feedItemStoresAllProperties(): void
    {
        $pubDate = new DateTimeImmutable('2026-01-01');

        $item = new FeedItem(
            title: 'Title',
            url: 'https://example.com/t/1',
            description: 'Description',
            publishedAt: $pubDate,
            author: 'user@example.com',
            categories: ['Cat1', 'Cat2'],
        );

        self::assertSame('Title', $item->title);
        self::assertSame('user@example.com', $item->author);
        self::assertCount(2, $item->categories);
        self::assertSame($pubDate, $item->publishedAt);
    }
}
