<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Content\RevisionService;
use Pulsar\Extension\Cms\Http\Controller\Admin\RevisionController;

use function count;
use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(RevisionController::class)]
final class RevisionControllerTest extends TestCase
{
    #[Test]
    public function index_returns_revisions_for_content(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $revision = new ContentRevision(
            id: 'rev-1',
            contentId: 'content-1',
            locale: 'en',
            revisionNumber: 1,
            title: 'Original Title',
            slug: 'original-title',
            body: 'Body text',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            authorId: 'author-1',
            reason: 'Initial publish',
            evidenceHash: 'hash123',
            createdAt: $now,
        );

        $content = $this->createContent('content-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $revisionRepo->method('findByContentAndLocale')->willReturn([$revision]);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request, 'content-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('content-1', $body['content_id']);

        /** @var list<array<string, mixed>> $revisions */
        $revisions = $body['revisions'];
        self::assertCount(1, $revisions);
        self::assertSame('rev-1', $revisions[0]['id']);
        self::assertSame(1, $revisions[0]['revision_number']);
    }

    #[Test]
    public function index_returns_404_when_content_not_found(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn(null);

        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function diff_returns_field_changes(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $fromRevision = new ContentRevision(
            id: 'rev-1',
            contentId: 'content-1',
            locale: 'en',
            revisionNumber: 1,
            title: 'Old Title',
            slug: 'old-title',
            body: 'Old body',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            authorId: 'author-1',
            reason: null,
            evidenceHash: 'hash1',
            createdAt: $now,
        );

        $toRevision = new ContentRevision(
            id: 'rev-2',
            contentId: 'content-1',
            locale: 'en',
            revisionNumber: 2,
            title: 'New Title',
            slug: 'new-title',
            body: 'New body',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            authorId: 'author-1',
            reason: 'Updated',
            evidenceHash: 'hash2',
            createdAt: $now,
        );

        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $revisionRepo->method('findById')->willReturnCallback(
            static fn(string $id): ?ContentRevision => match ($id) {
                'rev-1' => $fromRevision,
                'rev-2' => $toRevision,
                default => null,
            },
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
        );

        $request = $this->createAuthenticatedRequest(queryParams: [
            'from' => 'rev-1',
            'to' => 'rev-2',
        ]);

        $response = $controller->diff($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('rev-1', $body['from_revision_id']);
        self::assertSame('rev-2', $body['to_revision_id']);

        /** @var list<array<string, mixed>> $changes */
        $changes = $body['changes'];
        self::assertGreaterThanOrEqual(2, count($changes));
    }

    #[Test]
    public function diff_returns_400_when_ids_missing(): void
    {
        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
        );

        $request = $this->createAuthenticatedRequest();

        $response = $controller->diff($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function restore_returns_success(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $revision = new ContentRevision(
            id: 'rev-1',
            contentId: 'content-1',
            locale: 'en',
            revisionNumber: 1,
            title: 'Title',
            slug: 'title',
            body: 'Body',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            authorId: 'author-1',
            reason: null,
            evidenceHash: 'hash',
            createdAt: $now,
        );

        $content = $this->createContent('content-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $revisionRepo->method('findById')->willReturn($revision);

        // RevisionService is final readonly — need a stubbed service approach
        // Since RevisionService.restoreRevision calls translationRepository,
        // we stub the translation repo
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentAndLocale')->willReturn(
            new \Pulsar\Extension\Cms\Content\ContentTranslation(
                id: 'trans-1',
                contentId: 'content-1',
                locale: 'en',
                title: 'Current',
                slugSegment: 'current',
                path: '/current',
                body: 'Current body',
                excerpt: null,
                metaTitle: null,
                metaDescription: null,
                ogImageId: null,
                robots: null,
                structuredDataOverrides: [],
                readingTimeMinutes: null,
                bodyPlaintext: 'Current body',
                headingsText: '',
                customFieldsText: '',
                taxonomyTermsText: '',
            ),
        );

        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->restore($request, 'content-1', 'rev-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('content-1', $body['content_id']);
        self::assertSame('rev-1', $body['revision_id']);
        self::assertSame('restored', $body['status']);
    }

    #[Test]
    public function restore_returns_404_when_content_not_found(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn(null);

        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->restore($request, 'nonexistent', 'rev-1');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function restore_returns_404_when_revision_not_found(): void
    {
        $content = $this->createContent('content-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $revisionRepo->method('findById')->willReturn(null);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->restore($request, 'content-1', 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
        );

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest(), 'content-1');
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $revisionRepo = $this->createStub(ContentRevisionRepositoryInterface::class);
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $revisionService = new RevisionService(
            revisionRepository: $revisionRepo,
            translationRepository: $translationRepo,
        );

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new RevisionController(
            contentRepository: $contentRepo,
            revisionRepository: $revisionRepo,
            revisionService: $revisionService,
            config: new CmsConfig(),
            gate: $gate,
        );
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request, 'content-1');
    }

    private function createContent(string $id): Content
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new Content(
            id: $id,
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
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(array $queryParams = []): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/revisions');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/revisions');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
