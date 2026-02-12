<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Seo;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Seo\FeedGenerator;

#[CoversClass(FeedGenerator::class)]
final class FeedGeneratorTest extends TestCase
{
    private ContentRepositoryInterface&Stub $contentRepo;
    private ContentTranslationRepositoryInterface&Stub $translationRepo;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
    }

    #[Test]
    public function generateRssReturnsValidXmlWithItems(): void
    {
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $content = $this->buildContent('c1', $now);

        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );

        $translation = $this->buildTranslation('c1', 'en', 'about-us', 'About Us', 'Learn about us');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: 'My Site'));
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $rss = $generator->generateRss('en', 'https://example.com');

        self::assertStringContainsString('<?xml version="1.0"', $rss);
        self::assertStringContainsString('<rss version="2.0"', $rss);
        self::assertStringContainsString('<title>My Site</title>', $rss);
        self::assertStringContainsString('<title>About Us</title>', $rss);
        self::assertStringContainsString('<link>https://example.com/about-us</link>', $rss);
        self::assertStringContainsString('<description>Learn about us</description>', $rss);
        self::assertStringContainsString('<pubDate>', $rss);
    }

    #[Test]
    public function generateRssUsesMetaDescriptionWhenNoExcerpt(): void
    {
        $content = $this->buildContent('c1');
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );

        $translation = $this->buildTranslation('c1', 'en', 'page', 'Page', null, 'Meta desc');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $config = new CmsConfig();
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $rss = $generator->generateRss('en', 'https://example.com');

        self::assertStringContainsString('<description>Meta desc</description>', $rss);
    }

    #[Test]
    public function generateRssSkipsContentWithoutTranslation(): void
    {
        $content = $this->buildContent('c1');
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );
        $this->translationRepo->method('findByContentAndLocale')->willReturn(null);

        $config = new CmsConfig();
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $rss = $generator->generateRss('en', 'https://example.com');

        self::assertStringNotContainsString('<item>', $rss);
    }

    #[Test]
    public function generateRssEscapesHtmlEntities(): void
    {
        $content = $this->buildContent('c1');
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );

        $translation = $this->buildTranslation('c1', 'en', 'page', 'Title <script>', 'Desc & more');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $config = new CmsConfig();
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $rss = $generator->generateRss('en', 'https://example.com');

        self::assertStringContainsString('&lt;script&gt;', $rss);
        self::assertStringContainsString('&amp; more', $rss);
        self::assertStringNotContainsString('<script>', $rss);
    }

    #[Test]
    public function generateRssStripsTrailingSlashFromBaseUrl(): void
    {
        $content = $this->buildContent('c1');
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );

        $translation = $this->buildTranslation('c1', 'en', 'page', 'Page');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $config = new CmsConfig();
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $rss = $generator->generateRss('en', 'https://example.com/');

        self::assertStringContainsString('<link>https://example.com/page</link>', $rss);
        self::assertStringNotContainsString('example.com//page', $rss);
    }

    #[Test]
    public function generateAtomReturnsValidXmlWithEntries(): void
    {
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $content = $this->buildContent('c1', $now);

        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );

        $translation = $this->buildTranslation('c1', 'en', 'about', 'About', 'Summary text');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: 'Atom Site'));
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $atom = $generator->generateAtom('en', 'https://example.com');

        self::assertStringContainsString('<?xml version="1.0"', $atom);
        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $atom);
        self::assertStringContainsString('<title>Atom Site</title>', $atom);
        self::assertStringContainsString('<entry>', $atom);
        self::assertStringContainsString('<title>About</title>', $atom);
        self::assertStringContainsString('href="https://example.com/about"', $atom);
        self::assertStringContainsString('<summary>Summary text</summary>', $atom);
        self::assertStringContainsString('<published>', $atom);
    }

    #[Test]
    public function generateAtomSkipsContentWithoutTranslation(): void
    {
        $content = $this->buildContent('c1');
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );
        $this->translationRepo->method('findByContentAndLocale')->willReturn(null);

        $config = new CmsConfig();
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $atom = $generator->generateAtom('en', 'https://example.com');

        self::assertStringNotContainsString('<entry>', $atom);
    }

    #[Test]
    public function generateRssEmptyFeedHasNoItems(): void
    {
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20),
        );

        $config = new CmsConfig();
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $rss = $generator->generateRss('en', 'https://example.com');

        self::assertStringContainsString('<channel>', $rss);
        self::assertStringNotContainsString('<item>', $rss);
    }

    #[Test]
    public function generateRssFallsTitleSuffixToFeed(): void
    {
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20),
        );

        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: ''));
        $generator = new FeedGenerator($this->contentRepo, $this->translationRepo, $config);

        $rss = $generator->generateRss('en', 'https://example.com');

        self::assertStringContainsString('<title>Feed</title>', $rss);
    }

    private function buildContent(string $id, ?DateTimeImmutable $now = null): Content
    {
        $now ??= new DateTimeImmutable();

        return new Content(
            id: $id,
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'author-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );
    }

    private function buildTranslation(
        string $contentId,
        string $locale,
        string $path,
        string $title,
        ?string $excerpt = null,
        ?string $metaDescription = null,
    ): ContentTranslation {
        return new ContentTranslation(
            id: 'trans-' . $locale,
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $path,
            path: $path,
            body: '<p>Body</p>',
            excerpt: $excerpt,
            metaTitle: null,
            metaDescription: $metaDescription,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}
