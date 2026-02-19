<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
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
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function in_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Admin controller for content management operations.
 *
 * All actions require appropriate CMS permissions checked via GateInterface.
 * State-changing operations require CSRF token validation.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ContentController
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        private FieldRegistryRepositoryInterface $fieldRepository,
        private PublishingStateMachine $publishingStateMachine,
        private ContentLockServiceInterface $lockService,
        private EditorialWorkflowServiceInterface $workflowService,
        private SafeHtmlPolicy $safeHtmlPolicy,
        private GateInterface $gate,
        private CmsConfig $config,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.view');

        $locale = $this->resolveLocale($request);
        $contentType = $request->getQueryParams()['type'] ?? null;
        $status = $request->getQueryParams()['status'] ?? null;
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->getQueryParams()['per_page'] ?? 20)));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->contentRepository->findPublished(
            $locale,
            is_string($contentType) ? $contentType : null,
            $page,
            $perPage,
            $tenantId,
        );

        return Response::json([
            'data' => array_map(static fn(Content $c) => [
                'id' => $c->id,
                'type' => $c->contentType->value,
                'status' => $c->status->value,
                'author_id' => $c->authorId,
                'published_at' => $c->publishedAt?->format('c'),
                'created_at' => $c->createdAt->format('c'),
                'updated_at' => $c->updatedAt->format('c'),
            ], $result->items),
            'pagination' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.create');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $contentType = ContentType::tryFrom((string) ($body['content_type'] ?? 'page'));

        if ($contentType === null) {
            return Response::json(['error' => 'Invalid content type'], 400);
        }

        $locale = (string) ($body['locale'] ?? $this->config->defaultLocale);
        $title = (string) ($body['title'] ?? '');
        $slugSegment = (string) ($body['slug'] ?? '');
        $rawBody = (string) ($body['body'] ?? '');

        if ($title === '' || $slugSegment === '') {
            return Response::json(['error' => 'Title and slug are required'], 400);
        }

        $sanitizedBody = $this->safeHtmlPolicy->sanitize($rawBody);
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $contentId = $this->generateUuidV7();
        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: $identity->id(),
            tenantId: $tenantId,
            template: is_string($body['template'] ?? null) ? $body['template'] : null,
            parentId: is_string($body['parent_id'] ?? null) ? $body['parent_id'] : null,
            commentPolicy: CommentPolicy::tryFrom((string) ($body['comment_policy'] ?? '')) ?? CommentPolicy::Inherit,
            dataClassification: DataClassification::tryFrom((string) ($body['data_classification'] ?? '')) ?? DataClassification::Public,
        );

        $this->contentRepository->save($content);

        $path = $slugSegment;

        if ($content->parentId !== null) {
            $parentTranslation = $this->translationRepository->findByContentAndLocale($content->parentId, $locale);

            if ($parentTranslation !== null) {
                $path = $parentTranslation->path . '/' . $slugSegment;
            }
        }

        $translationId = $this->generateUuidV7();
        $translation = ContentTranslation::create(
            id: $translationId,
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $slugSegment,
            path: $path,
            body: $sanitizedBody,
            excerpt: is_string($body['excerpt'] ?? null) ? $body['excerpt'] : null,
            metaTitle: is_string($body['meta_title'] ?? null) ? $body['meta_title'] : null,
            metaDescription: is_string($body['meta_description'] ?? null) ? $body['meta_description'] : null,
        );

        $this->translationRepository->save($translation);

        return Response::json([
            'id' => $contentId,
            'status' => $content->status->value,
        ], 201);
    }

    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.view');

        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        $locale = $this->resolveLocale($request);
        $translations = $this->translationRepository->findByContentId($id);
        $blocks = $this->blockRepository->findByContentAndLocale($id, $locale);
        $customFields = $this->fieldRepository->findValues($id, $locale);
        $lock = $this->lockService->isLocked($id);

        // Build locale availability for admin locale tabs
        $translatedLocales = array_map(
            static fn(ContentTranslation $t) => $t->locale,
            $translations,
        );
        $localeAvailability = [];

        foreach ($this->config->supportedLocales as $supportedLocale) {
            $localeAvailability[$supportedLocale] = in_array($supportedLocale, $translatedLocales, true);
        }

        return Response::json([
            'content' => [
                'id' => $content->id,
                'type' => $content->contentType->value,
                'status' => $content->status->value,
                'author_id' => $content->authorId,
                'parent_id' => $content->parentId,
                'template' => $content->template,
                'published_at' => $content->publishedAt?->format('c'),
                'created_at' => $content->createdAt->format('c'),
                'updated_at' => $content->updatedAt->format('c'),
            ],
            'translations' => array_map(static fn(ContentTranslation $t) => [
                'id' => $t->id,
                'locale' => $t->locale,
                'title' => $t->title,
                'slug' => $t->slugSegment,
                'path' => $t->path,
                'excerpt' => $t->excerpt,
            ], $translations),
            'locale_availability' => $localeAvailability,
            'blocks' => array_map(static fn($b) => [
                'id' => $b->id,
                'type' => $b->blockType,
                'sort_order' => $b->sortOrder,
                'data' => $b->data,
            ], $blocks),
            'custom_fields' => array_map(static fn($f) => [
                'field_id' => $f->fieldId,
                'locale' => $f->locale,
            ], $customFields),
            'lock' => $lock !== null ? [
                'locked_by' => $lock->lockedBy,
                'expires_at' => $lock->expiresAt->format('c'),
            ] : null,
        ]);
    }

    public function update(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        // Check permission: edit_own for contributors, edit for editors
        if ($content->authorId === $identity->id()) {
            $this->authorize($identity, 'cms.content.edit_own');
        } else {
            $this->authorize($identity, 'cms.content.edit');
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $locale = (string) ($body['locale'] ?? $this->config->defaultLocale);
        $translation = $this->translationRepository->findByContentAndLocale($id, $locale);

        if ($translation === null) {
            return Response::json(['error' => 'Translation not found for locale'], 404);
        }

        $title = (string) ($body['title'] ?? $translation->title);
        $rawBody = (string) ($body['body'] ?? $translation->body);
        $sanitizedBody = $this->safeHtmlPolicy->sanitize($rawBody);
        $slugSegment = (string) ($body['slug'] ?? $translation->slugSegment);

        $path = $translation->path;

        if ($slugSegment !== $translation->slugSegment) {
            // Recompute path if slug changed
            if ($content->parentId !== null) {
                $parentTranslation = $this->translationRepository->findByContentAndLocale($content->parentId, $locale);
                $path = $parentTranslation !== null
                    ? $parentTranslation->path . '/' . $slugSegment
                    : $slugSegment;
            } else {
                $path = $slugSegment;
            }
        }

        $updatedTranslation = ContentTranslation::create(
            id: $translation->id,
            contentId: $id,
            locale: $locale,
            title: $title,
            slugSegment: $slugSegment,
            path: $path,
            body: $sanitizedBody,
            excerpt: is_string($body['excerpt'] ?? null) ? $body['excerpt'] : $translation->excerpt,
            metaTitle: is_string($body['meta_title'] ?? null) ? $body['meta_title'] : $translation->metaTitle,
            metaDescription: is_string($body['meta_description'] ?? null) ? $body['meta_description'] : $translation->metaDescription,
        );

        $this->translationRepository->save($updatedTranslation);

        return Response::json(['id' => $id, 'status' => 'updated']);
    }

    /**
     * Add a translation for an additional locale to existing content.
     *
     * Creates a new ContentTranslation for a locale that doesn't yet have one.
     * Used by the admin locale tabs to independently translate content per locale.
     */
    public function addTranslation(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.edit');

        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $locale = (string) ($body['locale'] ?? '');

        if ($locale === '' || !in_array($locale, $this->config->supportedLocales, true)) {
            return Response::json(['error' => 'Invalid or unsupported locale'], 400);
        }

        // Check if translation already exists for this locale
        $existing = $this->translationRepository->findByContentAndLocale($id, $locale);

        if ($existing !== null) {
            return Response::json(['error' => 'Translation already exists for this locale'], 409);
        }

        $title = (string) ($body['title'] ?? '');
        $slugSegment = (string) ($body['slug'] ?? '');
        $rawBody = (string) ($body['body'] ?? '');

        if ($title === '' || $slugSegment === '') {
            return Response::json(['error' => 'Title and slug are required'], 400);
        }

        $sanitizedBody = $this->safeHtmlPolicy->sanitize($rawBody);

        $path = $slugSegment;

        if ($content->parentId !== null) {
            $parentTranslation = $this->translationRepository->findByContentAndLocale($content->parentId, $locale);

            if ($parentTranslation !== null) {
                $path = $parentTranslation->path . '/' . $slugSegment;
            }
        }

        $translationId = $this->generateUuidV7();

        try {
            $translation = ContentTranslation::create(
                id: $translationId,
                contentId: $id,
                locale: $locale,
                title: $title,
                slugSegment: $slugSegment,
                path: $path,
                body: $sanitizedBody,
                excerpt: is_string($body['excerpt'] ?? null) ? $body['excerpt'] : null,
                metaTitle: is_string($body['meta_title'] ?? null) ? $body['meta_title'] : null,
                metaDescription: is_string($body['meta_description'] ?? null) ? $body['meta_description'] : null,
            );
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        $this->translationRepository->save($translation);

        return Response::json([
            'id' => $translationId,
            'content_id' => $id,
            'locale' => $locale,
            'status' => 'created',
        ], 201);
    }

    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.delete');
        $this->requireStepUp($request);

        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for content deletion',
            ], 400);
        }

        $this->contentRepository->delete($content);

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }

    public function publish(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.publish');

        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;

        try {
            $updated = $this->publishingStateMachine->transition(
                $content,
                PublishingStatus::Published,
                $identity->id(),
                $reason,
            );

            $this->contentRepository->save($updated);

            return Response::json(['id' => $id, 'status' => $updated->status->value]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function archive(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.archive');

        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;

        try {
            $updated = $this->publishingStateMachine->transition(
                $content,
                PublishingStatus::Archived,
                $identity->id(),
                $reason,
            );

            $this->contentRepository->save($updated);

            return Response::json(['id' => $id, 'status' => $updated->status->value]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function schedule(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.publish');

        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $publishAtStr = (string) ($body['publish_at'] ?? '');

        if ($publishAtStr === '') {
            return Response::json(['error' => 'publish_at is required'], 400);
        }

        $publishAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $publishAtStr);

        if ($publishAt === false) {
            $publishAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $publishAtStr);
        }

        if ($publishAt === false) {
            return Response::json(['error' => 'Invalid publish_at format'], 400);
        }

        try {
            $updated = $content->schedule($publishAt, $this->config->editorialWorkflow);
            $this->contentRepository->save($updated);

            return Response::json([
                'id' => $id,
                'status' => $updated->status->value,
                'scheduled_publish_at' => $publishAt->format('c'),
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function submitReview(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.submit_review');

        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : null;
        $reviewerId = is_string($body['reviewer_id'] ?? null) ? $body['reviewer_id'] : null;

        $review = $this->workflowService->submitForReview(
            $id,
            $identity->id(),
            $locale,
            $reviewerId,
        );

        try {
            $updated = $this->publishingStateMachine->transition(
                $content,
                PublishingStatus::InReview,
                $identity->id(),
                'Submitted for editorial review',
            );

            $this->contentRepository->save($updated);
        } catch (CmsException) {
            // Content may already be in review — the review record still stands
        }

        return Response::json([
            'review_id' => $review->id,
            'content_id' => $id,
            'status' => $review->status->value,
        ]);
    }

    public function acquireLock(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.edit_own');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : null;

        $lock = $this->lockService->acquire($id, $identity->id(), $locale);

        if ($lock === null) {
            $existingLock = $this->lockService->isLocked($id);

            return Response::json([
                'error' => 'Content is locked by another user',
                'locked_by' => $existingLock?->lockedBy,
                'expires_at' => $existingLock?->expiresAt->format('c'),
            ], 409);
        }

        return Response::json([
            'content_id' => $lock->contentId,
            'locked_by' => $lock->lockedBy,
            'expires_at' => $lock->expiresAt->format('c'),
        ]);
    }

    public function releaseLock(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.edit_own');

        $this->lockService->release($id, $identity->id());

        return Response::json(['content_id' => $id, 'status' => 'unlocked']);
    }

    private function requireStepUp(ServerRequestInterface $request): void
    {
        $stepUp = $request->getAttribute('step_up_verified', false);

        if ($stepUp !== true) {
            throw new RuntimeException('Step-up authentication required for this action');
        }
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : $this->config->defaultLocale;
    }

    private function generateUuidV7(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12),
        );
    }
}
