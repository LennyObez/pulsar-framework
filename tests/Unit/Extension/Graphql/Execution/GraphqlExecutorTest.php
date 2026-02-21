<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Graphql\Execution;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Graphql\Execution\GraphqlExecutor;
use Pulsar\Extension\Graphql\Resolver\ContentResolver;
use Pulsar\Extension\Graphql\Resolver\MediaResolver;
use Pulsar\Extension\Graphql\Resolver\TaxonomyResolver;
use Pulsar\Extension\Graphql\Schema\SchemaBuilder;

#[CoversClass(GraphqlExecutor::class)]
final class GraphqlExecutorTest extends TestCase
{
    private ContentRepositoryInterface&Stub $contentRepo;
    private ContentTranslationRepositoryInterface&Stub $translationRepo;
    private ContentBlockRepositoryInterface&Stub $blockRepo;
    private TaxonomyRepositoryInterface&Stub $taxonomyRepo;
    private MediaRepositoryInterface&Stub $mediaRepo;
    private GraphqlExecutor $executor;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $this->blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);
        $this->taxonomyRepo = $this->createStub(TaxonomyRepositoryInterface::class);
        $this->mediaRepo = $this->createStub(MediaRepositoryInterface::class);

        $schema = new SchemaBuilder()->build();
        $contentResolver = new ContentResolver($this->contentRepo, $this->translationRepo, $this->blockRepo);
        $taxonomyResolver = new TaxonomyResolver($this->taxonomyRepo);
        $mediaResolver = new MediaResolver($this->mediaRepo);

        $this->executor = new GraphqlExecutor($schema, $contentResolver, $taxonomyResolver, $mediaResolver);
    }

    #[Test]
    public function executes_simple_content_query(): void
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

        $query = '{ content(id: "c-001") { id contentType status } }';
        $result = $this->executor->execute($query);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertIsArray($result['data']['content']);
        self::assertSame('c-001', $result['data']['content']['id']);
        self::assertSame('article', $result['data']['content']['contentType']);
        self::assertSame('published', $result['data']['content']['status']);
    }

    #[Test]
    public function executes_content_query_with_null_result(): void
    {
        $this->contentRepo->method('findById')->willReturn(null);

        $query = '{ content(id: "nonexistent") { id } }';
        $result = $this->executor->execute($query);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertNull($result['data']['content']);
    }

    #[Test]
    public function executes_contents_list_query(): void
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
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );

        $this->contentRepo->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 10, currentPage: 1),
        );

        $query = '{ contents(locale: "en", perPage: 10) { totalCount page items { id contentType } } }';
        $result = $this->executor->execute($query);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertIsArray($result['data']['contents']);
        self::assertSame(1, $result['data']['contents']['totalCount']);
        self::assertIsArray($result['data']['contents']['items']);
        self::assertCount(1, $result['data']['contents']['items']);
        self::assertIsArray($result['data']['contents']['items'][0]);
        self::assertSame('c-002', $result['data']['contents']['items'][0]['id']);
    }

    #[Test]
    public function executes_taxonomy_query(): void
    {
        $now = new DateTimeImmutable('2025-03-10T12:00:00+00:00');
        $taxonomy = new Taxonomy(
            id: 'tax-001',
            tenantId: null,
            slug: 'categories',
            hierarchical: true,
            createdAt: $now,
        );

        $this->taxonomyRepo->method('findBySlug')->willReturn($taxonomy);

        $query = '{ taxonomy(slug: "categories") { id slug hierarchical } }';
        $result = $this->executor->execute($query);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertIsArray($result['data']['taxonomy']);
        self::assertSame('tax-001', $result['data']['taxonomy']['id']);
        self::assertSame('categories', $result['data']['taxonomy']['slug']);
        self::assertTrue($result['data']['taxonomy']['hierarchical']);
    }

    #[Test]
    public function executes_media_query(): void
    {
        $now = new DateTimeImmutable('2025-02-20T08:30:00+00:00');
        $asset = new MediaAsset(
            id: 'media-001',
            tenantId: null,
            uploaderId: 'u-001',
            filename: 'banner.png',
            storagePath: 'uploads/banner.png',
            disk: 'local',
            mimeType: 'image/png',
            fileSize: 102400,
            fileHash: 'hash123',
            width: 800,
            height: 600,
            exifData: null,
            altTextDefault: 'Banner',
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $this->mediaRepo->method('findById')->willReturn($asset);

        $query = '{ media(id: "media-001") { id filename mimeType fileSize width height } }';
        $result = $this->executor->execute($query);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertIsArray($result['data']['media']);
        self::assertSame('media-001', $result['data']['media']['id']);
        self::assertSame('banner.png', $result['data']['media']['filename']);
        self::assertSame(800, $result['data']['media']['width']);
    }

    #[Test]
    public function supports_field_aliases(): void
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

        $query = '{ myContent: content(id: "c-001") { identifier: id type: contentType } }';
        $result = $this->executor->execute($query);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertArrayHasKey('myContent', $result['data']);
        self::assertIsArray($result['data']['myContent']);
        self::assertSame('c-001', $result['data']['myContent']['identifier']);
        self::assertSame('article', $result['data']['myContent']['type']);
    }

    #[Test]
    public function supports_variables(): void
    {
        $this->contentRepo->method('findById')->willReturn(null);

        $query = 'query GetContent($contentId: ID!) { content(id: $contentId) { id } }';
        $result = $this->executor->execute($query, ['contentId' => 'c-999']);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertNull($result['data']['content']);
    }

    #[Test]
    public function returns_error_for_unknown_root_field(): void
    {
        $query = '{ unknownField { id } }';
        $result = $this->executor->execute($query);

        self::assertNotSame([], $result['errors']);
        self::assertIsArray($result['errors'][0]);
        self::assertIsString($result['errors'][0]['message']);
        self::assertStringContainsString('Unknown root field', $result['errors'][0]['message']);
    }

    #[Test]
    public function returns_error_for_syntax_error(): void
    {
        $query = '{ content(id: "c-001" { id }';
        $result = $this->executor->execute($query);

        self::assertNotSame([], $result['errors']);
        self::assertIsArray($result['errors'][0]);
        self::assertIsString($result['errors'][0]['message']);
        self::assertStringContainsString('syntax error', $result['errors'][0]['message']);
    }

    #[Test]
    public function resolves_content_with_translations(): void
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

        $translation = new ContentTranslation(
            id: 't-001',
            contentId: 'c-001',
            locale: 'en',
            title: 'Hello',
            slugSegment: 'hello',
            path: 'hello',
            body: '<p>World</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'World',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $this->contentRepo->method('findById')->willReturn($content);
        $this->translationRepo->method('findByContentId')->willReturn([$translation]);

        $query = '{ content(id: "c-001") { id translations { locale title path } } }';
        $result = $this->executor->execute($query);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertIsArray($result['data']['content']);
        self::assertIsArray($result['data']['content']['translations']);
        self::assertCount(1, $result['data']['content']['translations']);
        self::assertIsArray($result['data']['content']['translations'][0]);
        self::assertSame('en', $result['data']['content']['translations'][0]['locale']);
        self::assertSame('Hello', $result['data']['content']['translations'][0]['title']);
    }

    #[Test]
    public function supports_fragments(): void
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

        $query = <<<'GRAPHQL'
            query {
                content(id: "c-001") {
                    ...ContentFields
                }
            }

            fragment ContentFields on Content {
                id
                contentType
                status
            }
            GRAPHQL;

        $result = $this->executor->execute($query);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['data']);
        self::assertIsArray($result['data']['content']);
        self::assertSame('c-001', $result['data']['content']['id']);
        self::assertSame('article', $result['data']['content']['contentType']);
        self::assertSame('published', $result['data']['content']['status']);
    }
}
