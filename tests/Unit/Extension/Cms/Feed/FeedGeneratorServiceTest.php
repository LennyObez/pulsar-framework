<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Feed;

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
use Pulsar\Extension\Cms\Internal\Newsletter\FeedGeneratorService;

#[CoversClass(FeedGeneratorService::class)]
final class FeedGeneratorServiceTest extends TestCase
{
    private ContentRepositoryInterface&Stub $contentRepo;
    private ContentTranslationRepositoryInterface&Stub $translationRepo;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
    }

    #[Test]
    public function rssOutputContainsRssVersionTag(): void
    {
        $now = new DateTimeImmutable('2026-01-15T12:00:00+00:00');
        $content = $this->buildContent('c1', $now);

        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );

        $translation = $this->buildTranslation('c1', 'en', 'hello-world', 'Hello World', 'Excerpt here');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: 'My Blog'));
        $service = new FeedGeneratorService(
            $this->contentRepo,
            $this->translationRepo,
            $config,
            'https://example.com',
        );

        $xml = $service->generate('article', 'en', 'rss');

        self::assertStringContainsString('<?xml version="1.0"', $xml);
        self::assertStringContainsString('<rss version="2.0"', $xml);
        self::assertStringContainsString('<title>My Blog</title>', $xml);
        self::assertStringContainsString('<title>Hello World</title>', $xml);
        self::assertStringContainsString('<link>https://example.com/hello-world</link>', $xml);
    }

    #[Test]
    public function atomOutputContainsFeedNamespace(): void
    {
        $now = new DateTimeImmutable('2026-01-15T12:00:00+00:00');
        $content = $this->buildContent('c1', $now);

        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20),
        );

        $translation = $this->buildTranslation('c1', 'en', 'hello-world', 'Hello World');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: 'My Blog'));
        $service = new FeedGeneratorService(
            $this->contentRepo,
            $this->translationRepo,
            $config,
            'https://example.com',
        );

        $xml = $service->generate('article', 'en', 'atom');

        self::assertStringContainsString('<?xml version="1.0"', $xml);
        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom"', $xml);
        self::assertStringContainsString('<title>My Blog</title>', $xml);
        self::assertStringContainsString('<title>Hello World</title>', $xml);
    }

    #[Test]
    public function emptyContentReturnsValidXmlWithNoItems(): void
    {
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20),
        );

        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: 'Empty'));
        $service = new FeedGeneratorService(
            $this->contentRepo,
            $this->translationRepo,
            $config,
            'https://example.com',
        );

        $rss = $service->generate('article', 'en', 'rss');
        self::assertStringContainsString('<rss version="2.0"', $rss);
        self::assertStringNotContainsString('<item>', $rss);

        $atom = $service->generate('article', 'en', 'atom');
        self::assertStringContainsString('<feed xmlns', $atom);
        self::assertStringNotContainsString('<entry>', $atom);
    }

    #[Test]
    public function itemLimitIsRespected(): void
    {
        $now = new DateTimeImmutable('2026-01-15T12:00:00+00:00');
        $content = $this->buildContent('c1', $now);

        // When limit is applied, the repository should only return those items
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 5),
        );

        $translation = $this->buildTranslation('c1', 'en', 'only-item', 'Only Item');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: 'Feed'));
        $service = new FeedGeneratorService(
            $this->contentRepo,
            $this->translationRepo,
            $config,
            'https://example.com',
        );

        $xml = $service->generate('article', 'en', 'rss', 5);

        self::assertStringContainsString('<item>', $xml);
        self::assertSame(1, substr_count($xml, '<item>'));
    }

    #[Test]
    public function rssFallsBackToFeedTitleWhenSuffixEmpty(): void
    {
        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20),
        );

        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: ''));
        $service = new FeedGeneratorService(
            $this->contentRepo,
            $this->translationRepo,
            $config,
            'https://example.com',
        );

        $rss = $service->generate('article', 'en', 'rss');
        self::assertStringContainsString('<title>Feed</title>', $rss);
    }

    private function buildContent(string $id, DateTimeImmutable $date): Content
    {
        return new Content(
            id: $id,
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'author-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $date,
            createdAt: $date,
            updatedAt: $date,
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
        string $slug,
        string $title,
        ?string $excerpt = null,
    ): ContentTranslation {
        return new ContentTranslation(
            id: 'tr-' . $contentId,
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $slug,
            path: $slug,
            body: '<p>Content body</p>',
            excerpt: $excerpt,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Content body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}
