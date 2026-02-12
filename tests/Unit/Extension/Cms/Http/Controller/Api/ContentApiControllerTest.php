<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\Api\ContentApiController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

#[CoversClass(ContentApiController::class)]
final class ContentApiControllerTest extends TestCase
{
    private ContentRepositoryInterface&Stub $contentRepository;
    private ContentTranslationRepositoryInterface&Stub $translationRepository;
    private ContentBlockRepositoryInterface&Stub $blockRepository;
    private FieldRegistryRepositoryInterface&Stub $fieldRepository;
    private CmsConfig $config;
    private ContentApiController $controller;

    protected function setUp(): void
    {
        $this->contentRepository = $this->createStub(ContentRepositoryInterface::class);
        $this->translationRepository = $this->createStub(ContentTranslationRepositoryInterface::class);
        $this->blockRepository = $this->createStub(ContentBlockRepositoryInterface::class);
        $this->fieldRepository = $this->createStub(FieldRegistryRepositoryInterface::class);
        $this->config = new CmsConfig();

        $this->controller = new ContentApiController(
            $this->contentRepository,
            $this->translationRepository,
            $this->blockRepository,
            $this->fieldRepository,
            $this->config,
        );
    }

    #[Test]
    public function index_returns_paginated_content_with_headers(): void
    {
        $now = new DateTimeImmutable();
        $content = new Content(
            id: '019577a0-0000-7000-8000-000000000001',
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
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );

        $this->contentRepository->method('findPublished')->willReturn(
            new PaginationResult(
                items: [$content],
                total: 1,
                hasMore: false,
                perPage: 20,
                currentPage: 1,
            ),
        );

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/content');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-Total-Count'));
        self::assertSame('1', $response->getHeaderLine('X-Page'));
        self::assertSame('20', $response->getHeaderLine('X-Per-Page'));

        /** @var array{data: list<array{id: string}>, pagination: array<string, mixed>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('019577a0-0000-7000-8000-000000000001', $body['data'][0]['id']);
    }

    #[Test]
    public function index_with_field_selection_filters_fields(): void
    {
        $now = new DateTimeImmutable();
        $content = new Content(
            id: '019577a0-0000-7000-8000-000000000001',
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
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );

        $this->contentRepository->method('findPublished')->willReturn(
            new PaginationResult(items: [$content], total: 1, hasMore: false, perPage: 20, currentPage: 1),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/content',
            queryParams: ['fields' => 'id,type'],
        );

        $response = $this->controller->index($request);

        /** @var array{data: list<array<string, mixed>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('id', $body['data'][0]);
        self::assertArrayHasKey('type', $body['data'][0]);
        self::assertArrayNotHasKey('author_id', $body['data'][0]);
    }

    #[Test]
    public function show_returns_404_for_missing_content(): void
    {
        $this->contentRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/content/nonexistent');

        $response = $this->controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());

        /** @var array{error: string, status: int} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Content not found', $body['error']);
        self::assertSame(404, $body['status']);
    }

    #[Test]
    public function show_returns_content_with_related_data(): void
    {
        $now = new DateTimeImmutable();
        $content = new Content(
            id: 'content-1',
            tenantId: null,
            contentType: ContentType::Article,
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
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );

        $this->contentRepository->method('findById')->willReturn($content);
        $this->translationRepository->method('findByContentId')->willReturn([]);
        $this->blockRepository->method('findByContentAndLocale')->willReturn([]);
        $this->fieldRepository->method('findValues')->willReturn([]);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/content/content-1');

        $response = $this->controller->show($request, 'content-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: array{content: array{id: string, type: string}, translations: mixed, blocks: mixed, custom_fields: mixed}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('content-1', $body['data']['content']['id']);
        self::assertSame('article', $body['data']['content']['type']);
        self::assertArrayHasKey('translations', $body['data']);
        self::assertArrayHasKey('blocks', $body['data']);
        self::assertArrayHasKey('custom_fields', $body['data']);
    }

    #[Test]
    public function create_returns_422_for_invalid_payload(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/content',
            parsedBody: [],
        );

        $response = $this->controller->create($request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array{error: string, status: int, details: array<string, string>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Validation failed', $body['error']);
        self::assertArrayHasKey('title', $body['details']);
        self::assertArrayHasKey('slug', $body['details']);
    }

    #[Test]
    public function create_returns_201_for_valid_payload(): void
    {
        $this->contentRepository->method('save');
        $this->translationRepository->method('save');

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/content',
            parsedBody: [
                'title' => 'Test Page',
                'slug' => 'test-page',
                'body' => '<p>Content body</p>',
                'content_type' => 'page',
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array{data: array{id: string, status: string}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertNotEmpty($body['data']['id']);
        self::assertSame('draft', $body['data']['status']);
    }

    #[Test]
    public function update_returns_404_for_missing_content(): void
    {
        $this->contentRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/api/v1/content/missing',
            parsedBody: ['title' => 'Updated'],
        );

        $response = $this->controller->update($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_404_for_missing_content(): void
    {
        $this->contentRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'DELETE', uri: '/api/v1/content/missing');

        $response = $this->controller->delete($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_soft_deletes_existing_content(): void
    {
        $now = new DateTimeImmutable();
        $content = new Content(
            id: 'content-1',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'author-1',
            status: PublishingStatus::Draft,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );

        $this->contentRepository->method('findById')->willReturn($content);

        $request = new ServerRequest(method: 'DELETE', uri: '/api/v1/content/content-1');

        $response = $this->controller->delete($request, 'content-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: array{id: string, status: string}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('content-1', $body['data']['id']);
        self::assertSame('deleted', $body['data']['status']);
    }
}
