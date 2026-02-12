<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Graphql\Resolver;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Graphql\Resolver\ContentResolver;

#[CoversClass(ContentResolver::class)]
final class ContentResolverTest extends TestCase
{
    private ContentRepositoryInterface&Stub $contentRepo;
    private ContentTranslationRepositoryInterface&Stub $translationRepo;
    private ContentBlockRepositoryInterface&Stub $blockRepo;
    private ContentResolver $resolver;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $this->blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);

        $this->resolver = new ContentResolver(
            $this->contentRepo,
            $this->translationRepo,
            $this->blockRepo,
        );
    }

    #[Test]
    public function resolve_by_id_returns_null_when_not_found(): void
    {
        $this->contentRepo->method('findById')->willReturn(null);

        self::assertNull($this->resolver->resolveById('nonexistent'));
    }

    #[Test]
    public function resolve_by_id_returns_content_data(): void
    {
        $now = new DateTimeImmutable('2025-01-15T10:00:00+00:00');
        $content = new Content(
            id: 'c-001',
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'u-001',
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

        $this->contentRepo->method('findById')->willReturn($content);

        $result = $this->resolver->resolveById('c-001');

        self::assertNotNull($result);
        self::assertSame('c-001', $result['id']);
        self::assertSame('article', $result['contentType']);
        self::assertSame('published', $result['status']);
        self::assertSame('u-001', $result['authorId']);
        self::assertSame('open', $result['commentPolicy']);
        self::assertSame(0, $result['sortOrder']);
    }

    #[Test]
    public function resolve_list_returns_paginated_content(): void
    {
        $now = new DateTimeImmutable('2025-01-15T10:00:00+00:00');
        $content = new Content(
            id: 'c-002',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'u-001',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: 'custom',
            parentId: null,
            sortOrder: 1,
            commentPolicy: CommentPolicy::Closed,
            dataClassification: DataClassification::Public,
        );

        $pagination = new PaginationResult(
            items: [$content],
            total: 1,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
        );

        $this->contentRepo->method('findPublished')->willReturn($pagination);

        $result = $this->resolver->resolveList('en', null, 1, 20);

        self::assertSame(1, $result['totalCount']);
        self::assertSame(1, $result['page']);
        self::assertSame(20, $result['perPage']);
        self::assertIsArray($result['items']);
        self::assertCount(1, $result['items']);
        self::assertIsArray($result['items'][0]);
        self::assertSame('c-002', $result['items'][0]['id']);
    }

    #[Test]
    public function resolve_translations_returns_translation_data(): void
    {
        $translation = new ContentTranslation(
            id: 't-001',
            contentId: 'c-001',
            locale: 'en',
            title: 'Test Article',
            slugSegment: 'test-article',
            path: 'test-article',
            body: '<p>Hello world</p>',
            excerpt: 'A test',
            metaTitle: 'Test',
            metaDescription: 'Description',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 2,
            bodyPlaintext: 'Hello world',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $this->translationRepo->method('findByContentId')->willReturn([$translation]);

        $result = $this->resolver->resolveTranslations('c-001');

        self::assertCount(1, $result);
        self::assertSame('t-001', $result[0]['id']);
        self::assertSame('en', $result[0]['locale']);
        self::assertSame('Test Article', $result[0]['title']);
        self::assertSame('test-article', $result[0]['path']);
        self::assertSame(2, $result[0]['readingTimeMinutes']);
    }

    #[Test]
    public function resolve_blocks_returns_block_data(): void
    {
        $now = new DateTimeImmutable('2025-01-15T10:00:00+00:00');
        $block = new ContentBlock(
            id: 'b-001',
            contentId: 'c-001',
            locale: 'en',
            blockType: 'text',
            sortOrder: 0,
            data: ['content' => 'Hello'],
            createdAt: $now,
            updatedAt: $now,
        );

        $this->blockRepo->method('findByContentAndLocale')->willReturn([$block]);

        $result = $this->resolver->resolveBlocks('c-001', 'en');

        self::assertCount(1, $result);
        self::assertSame('b-001', $result[0]['id']);
        self::assertSame('text', $result[0]['blockType']);
        self::assertSame('{"content":"Hello"}', $result[0]['data']);
    }
}
