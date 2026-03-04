<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\FieldRegistry\ContentFieldValue;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\ContentController;
use Pulsar\Extension\Cms\Workflow\ContentLock;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Extension\Cms\Workflow\ReviewStatus;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ContentController::class)]
final class ContentControllerTest extends TestCase
{
    #[Test]
    public function index_returns_content_listing(): void
    {
        $content = $this->createContent('c-1');
        $result = new PaginationResult(
            items: [$content],
            total: 1,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findPublished')->willReturn($result);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body['items']);

        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];
        self::assertCount(1, $items);
        self::assertSame('c-1', $items[0]['id']);
        self::assertSame('page', $items[0]['type']);
    }

    #[Test]
    public function create_returns_201_with_valid_data(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'content_type' => 'page',
            'title' => 'My New Page',
            'slug' => 'my-new-page',
            'body' => '<p>Hello world</p>',
        ]);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('draft', $body['status']);
        self::assertIsString($body['id']);
    }

    #[Test]
    public function create_returns_400_when_title_empty(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'content_type' => 'page',
            'title' => '',
            'slug' => 'my-page',
        ]);

        $response = $controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_400_when_slug_empty(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'content_type' => 'page',
            'title' => 'My Page',
            'slug' => '',
        ]);

        $response = $controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_400_for_invalid_content_type(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'content_type' => 'invalid_type',
            'title' => 'My Page',
            'slug' => 'my-page',
        ]);

        $response = $controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function show_returns_content_with_translations_and_blocks(): void
    {
        $content = $this->createContent('c-1');
        $translation = $this->createTranslation('t-1', 'c-1');
        $block = ContentBlock::text('b-1', 'c-1', 'en', 0, ['content' => 'Hello']);
        $fieldValue = new ContentFieldValue(
            id: 'fv-1',
            contentId: 'c-1',
            fieldId: 'f-1',
            locale: 'en',
            valueString: 'test',
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn([$translation]);

        $blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);
        $blockRepo->method('findByContentAndLocale')->willReturn([$block]);

        $fieldRepo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $fieldRepo->method('findValues')->willReturn([$fieldValue]);

        $lockService = $this->createStub(ContentLockServiceInterface::class);
        $lockService->method('getLockInfo')->willReturn(null);

        $controller = $this->createController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $blockRepo,
            fieldRepository: $fieldRepo,
            lockService: $lockService,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $contentData */
        $contentData = $body['content'];
        self::assertSame('c-1', $contentData['id']);
        self::assertSame('draft', $contentData['status']);

        self::assertIsArray($body['translations']);
        self::assertIsArray($body['blocks']);
        self::assertIsArray($body['custom_fields']);
        self::assertNull($body['lock']);
    }

    #[Test]
    public function show_returns_404_when_not_found(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn(null);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function show_includes_lock_info_when_locked(): void
    {
        $content = $this->createContent('c-1');
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $lock = new ContentLock(
            contentId: 'c-1',
            lockedBy: 'user-2',
            lockedAt: $now,
            expiresAt: new DateTimeImmutable('2026-03-10T12:30:00+00:00'),
            locale: null,
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn([]);

        $blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);
        $blockRepo->method('findByContentAndLocale')->willReturn([]);

        $fieldRepo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $fieldRepo->method('findValues')->willReturn([]);

        $lockService = $this->createStub(ContentLockServiceInterface::class);
        $lockService->method('getLockInfo')->willReturn($lock);

        $controller = $this->createController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $blockRepo,
            fieldRepository: $fieldRepo,
            lockService: $lockService,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'c-1');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $lockData */
        $lockData = $body['lock'];
        self::assertSame('user-2', $lockData['locked_by']);
    }

    #[Test]
    public function update_returns_success(): void
    {
        $content = $this->createContent('c-1', authorId: 'admin-1');
        $translation = $this->createTranslation('t-1', 'c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $controller = $this->createController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
        );
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'title' => 'Updated Title',
            'body' => '<p>Updated body</p>',
        ]);

        $response = $controller->update($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated', $body['status']);
    }

    #[Test]
    public function update_returns_404_when_content_not_found(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn(null);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_404_when_translation_not_found(): void
    {
        $content = $this->createContent('c-1', authorId: 'admin-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentAndLocale')->willReturn(null);

        $controller = $this->createController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
        );
        $request = $this->createAuthenticatedRequest(parsedBody: ['locale' => 'fr']);

        $response = $controller->update($request, 'c-1');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function add_translation_returns_201(): void
    {
        $content = $this->createContent('c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentAndLocale')->willReturn(null);

        $controller = $this->createController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
        );
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'locale' => 'en',
            'title' => 'French Title',
            'slug' => 'french-title',
            'body' => '<p>Bonjour</p>',
        ]);

        $response = $controller->addTranslation($request, 'c-1');

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('created', $body['status']);
        self::assertSame('c-1', $body['content_id']);
    }

    #[Test]
    public function add_translation_returns_409_when_already_exists(): void
    {
        $content = $this->createContent('c-1');
        $translation = $this->createTranslation('t-1', 'c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $controller = $this->createController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
        );
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'locale' => 'en',
            'title' => 'Title',
            'slug' => 'slug',
        ]);

        $response = $controller->addTranslation($request, 'c-1');

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function add_translation_returns_400_for_unsupported_locale(): void
    {
        $content = $this->createContent('c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'locale' => 'zz-unsupported',
            'title' => 'Title',
            'slug' => 'slug',
        ]);

        $response = $controller->addTranslation($request, 'c-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function add_translation_returns_400_when_title_empty(): void
    {
        $content = $this->createContent('c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentAndLocale')->willReturn(null);

        $controller = $this->createController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
        );
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'locale' => 'en',
            'title' => '',
            'slug' => 'slug',
        ]);

        $response = $controller->addTranslation($request, 'c-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_success(): void
    {
        $content = $this->createContent('c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'Content is outdated and no longer relevant'],
        );

        $response = $controller->delete($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_returns_400_when_reason_too_short(): void
    {
        $content = $this->createContent('c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'short'],
        );

        $response = $controller->delete($request, 'c-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_404_when_not_found(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn(null);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'Removing content permanently'],
        );

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function publish_returns_success(): void
    {
        // Draft -> Published is a valid transition
        $content = $this->createContent('c-1', status: PublishingStatus::Draft);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->publish($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('published', $body['status']);
    }

    #[Test]
    public function publish_returns_422_on_invalid_transition(): void
    {
        // Archived -> Published is NOT a valid transition
        $content = $this->createContent('c-1', status: PublishingStatus::Archived);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->publish($request, 'c-1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function publish_returns_404_when_not_found(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn(null);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->publish($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function archive_returns_success(): void
    {
        // Published -> Archived is a valid transition
        $content = $this->createContent('c-1', status: PublishingStatus::Published);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->archive($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('archived', $body['status']);
    }

    #[Test]
    public function schedule_returns_success(): void
    {
        $content = $this->createContent('c-1');
        $scheduled = $this->createContent('c-1', status: PublishingStatus::Scheduled);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'publish_at' => '2026-04-01T10:00:00+00:00',
        ]);

        $response = $controller->schedule($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['scheduled_publish_at']);
    }

    #[Test]
    public function schedule_returns_400_when_publish_at_missing(): void
    {
        $content = $this->createContent('c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest(parsedBody: []);

        $response = $controller->schedule($request, 'c-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function schedule_returns_400_for_invalid_date_format(): void
    {
        $content = $this->createContent('c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'publish_at' => 'not-a-date',
        ]);

        $response = $controller->schedule($request, 'c-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function submit_review_returns_success(): void
    {
        // Draft -> InReview requires editorialWorkflow=true in canTransitionTo,
        // but the controller catches CmsException gracefully if transition fails
        $content = $this->createContent('c-1');
        $review = new EditorialReview(
            id: 'rev-1',
            contentId: 'c-1',
            locale: null,
            requestedBy: 'admin-1',
            reviewerId: null,
            status: ReviewStatus::Pending,
            comment: null,
            decisionReason: null,
            createdAt: new DateTimeImmutable('2026-03-10T12:00:00+00:00'),
            decidedAt: null,
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $workflowService = $this->createStub(EditorialWorkflowServiceInterface::class);
        $workflowService->method('submitForReview')->willReturn($review);

        // Use real PublishingStateMachine — the transition may throw CmsException
        // but the controller catches it gracefully (review record still stands)
        $controller = $this->createController(
            contentRepository: $contentRepo,
            workflowService: $workflowService,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->submitReview($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('rev-1', $body['review_id']);
        self::assertSame('pending', $body['status']);
    }

    #[Test]
    public function acquire_lock_returns_lock_info(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $lock = new ContentLock(
            contentId: 'c-1',
            lockedBy: 'admin-1',
            lockedAt: $now,
            expiresAt: new DateTimeImmutable('2026-03-10T12:30:00+00:00'),
            locale: null,
        );

        $lockService = $this->createStub(ContentLockServiceInterface::class);
        $lockService->method('acquire')->willReturn($lock);

        $controller = $this->createController(lockService: $lockService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->acquireLock($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('admin-1', $body['locked_by']);
        self::assertSame('c-1', $body['content_id']);
    }

    #[Test]
    public function acquire_lock_returns_409_when_contention(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $existingLock = new ContentLock(
            contentId: 'c-1',
            lockedBy: 'user-2',
            lockedAt: $now,
            expiresAt: new DateTimeImmutable('2026-03-10T12:30:00+00:00'),
            locale: null,
        );

        $lockService = $this->createStub(ContentLockServiceInterface::class);
        $lockService->method('acquire')->willReturn(null);
        $lockService->method('isLocked')->willReturn($existingLock);

        $controller = $this->createController(lockService: $lockService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->acquireLock($request, 'c-1');

        self::assertSame(409, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('user-2', $body['locked_by']);
    }

    #[Test]
    public function release_lock_returns_success(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->releaseLock($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unlocked', $body['status']);
    }

    #[Test]
    public function break_lock_returns_success(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->breakLock($request, 'c-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $controller = $this->createController();

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    #[Test]
    public function delete_requires_step_up(): void
    {
        $content = $this->createContent('c-1');

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $controller = $this->createController(contentRepository: $contentRepo);
        // No stepUp
        $request = $this->createAuthenticatedRequest(
            stepUp: false,
            parsedBody: ['reason' => 'A long enough reason'],
        );

        $this->expectException(AuthorizationException::class);
        $controller->delete($request, 'c-1');
    }

    private function createController(
        ?ContentRepositoryInterface $contentRepository = null,
        ?ContentTranslationRepositoryInterface $translationRepository = null,
        ?ContentBlockRepositoryInterface $blockRepository = null,
        ?FieldRegistryRepositoryInterface $fieldRepository = null,
        ?PublishingStateMachine $publishingStateMachine = null,
        ?ContentLockServiceInterface $lockService = null,
        ?EditorialWorkflowServiceInterface $workflowService = null,
        ?GateInterface $gate = null,
    ): ContentController {
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $safeHtmlPolicy = new SafeHtmlPolicy($auditLogger);

        $config = $this->createCmsConfig();

        return new ContentController(
            contentRepository: $contentRepository ?? $this->createStub(ContentRepositoryInterface::class),
            translationRepository: $translationRepository ?? $this->createStub(ContentTranslationRepositoryInterface::class),
            blockRepository: $blockRepository ?? $this->createStub(ContentBlockRepositoryInterface::class),
            fieldRepository: $fieldRepository ?? $this->createStub(FieldRegistryRepositoryInterface::class),
            publishingStateMachine: $publishingStateMachine ?? new PublishingStateMachine(),
            lockService: $lockService ?? $this->createStub(ContentLockServiceInterface::class),
            workflowService: $workflowService ?? $this->createStub(EditorialWorkflowServiceInterface::class),
            safeHtmlPolicy: $safeHtmlPolicy,
            config: $config,
            gate: $gate,
        );
    }

    private function createCmsConfig(): CmsConfig
    {
        return CmsConfig::fromArray([
            'default_locale' => 'en',
            'supported_locales' => ['en', 'fr', 'de'],
        ]);
    }

    private function createContent(
        string $id,
        PublishingStatus $status = PublishingStatus::Draft,
        string $authorId = 'admin-1',
    ): Content {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new Content(
            id: $id,
            tenantId: null,
            contentType: ContentType::Page,
            authorId: $authorId,
            status: $status,
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
    }

    private function createTranslation(string $id, string $contentId): ContentTranslation
    {
        return new ContentTranslation(
            id: $id,
            contentId: $contentId,
            locale: 'en',
            title: 'Test Page',
            slugSegment: 'test-page',
            path: 'test-page',
            body: '<p>Test content</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Test content',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/content');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/content');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
