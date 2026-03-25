<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
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
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function in_array;
use function is_string;
use function strlen;

/**
 * Admin controller for content management operations.
 *
 * All actions require appropriate CMS permissions checked via GateInterface.
 * State-changing operations require CSRF token validation.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class ContentController extends AbstractAdminController
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
        private CmsConfig $config,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.view');

        $locale = $this->resolveLocale($request);
        $contentType = $request->getQueryParams()['type'] ?? null;
        $pageParam = $request->getQueryParams()['page'] ?? 1;
        $page = max(1, is_numeric($pageParam) ? (int) $pageParam : 1);
        $perPageParam = $request->getQueryParams()['per_page'] ?? 20;
        $perPage = min(100, max(1, is_numeric($perPageParam) ? (int) $perPageParam : 20));

        $tenantId = $this->validateTenantAccess($request);

        $result = $this->contentRepository->findPublished(
            $locale,
            is_string($contentType) ? $contentType : null,
            $page,
            $perPage,
            $tenantId,
        );

        $data = [
            'items' => array_map(static fn(Content $c) => [
                'id' => $c->id,
                'type' => $c->contentType->value,
                'status' => $c->status->value,
                'author_id' => $c->authorId,
                'published_at' => $c->publishedAt?->format('c'),
                'created_at' => $c->createdAt->format('c'),
                'updated_at' => $c->updatedAt->format('c'),
            ], $result->items),
            'pagination' => [
                'page' => $result->currentPage,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ];

        return $this->respondWithView($request, 'admin.content.index', $data);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.create');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $rawContentTypeValue = $body['content_type'] ?? null;
        $contentTypeStr = is_string($rawContentTypeValue) ? $rawContentTypeValue : 'page';
        $contentType = ContentType::tryFrom($contentTypeStr);

        if ($contentType === null) {
            return Response::json(['error' => 'Invalid content type'], 400);
        }

        $rawLocale = $body['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : $this->config->defaultLocale;
        $rawTitle = $body['title'] ?? null;
        $title = is_string($rawTitle) ? $rawTitle : '';
        $rawSlug = $body['slug'] ?? null;
        $slugSegment = is_string($rawSlug) ? $rawSlug : '';
        $rawBodyText = $body['body'] ?? null;
        $rawBody = is_string($rawBodyText) ? $rawBodyText : '';

        if ($title === '' || $slugSegment === '') {
            return Response::json(['error' => 'Title and slug are required'], 400);
        }

        $sanitizedBody = $this->safeHtmlPolicy->sanitize($rawBody);
        $tenantId = $this->validateTenantAccess($request);

        $rawTemplate = $body['template'] ?? null;
        $rawParentId = $body['parent_id'] ?? null;
        $rawCommentPolicy = $body['comment_policy'] ?? null;
        $rawDataClassification = $body['data_classification'] ?? null;
        $contentId = UuidGenerator::v7();
        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: $identity->id(),
            tenantId: $tenantId,
            template: is_string($rawTemplate) ? $rawTemplate : null,
            parentId: is_string($rawParentId) ? $rawParentId : null,
            commentPolicy: CommentPolicy::tryFrom(is_string($rawCommentPolicy) ? $rawCommentPolicy : '') ?? CommentPolicy::Inherit,
            dataClassification: DataClassification::tryFrom(is_string($rawDataClassification) ? $rawDataClassification : '') ?? DataClassification::Public,
        );

        $this->contentRepository->save($content);

        $path = $slugSegment;

        if ($content->parentId !== null) {
            $parentTranslation = $this->translationRepository->findByContentAndLocale($content->parentId, $locale);

            if ($parentTranslation !== null) {
                $path = $parentTranslation->path . '/' . $slugSegment;
            }
        }

        $rawExcerpt = $body['excerpt'] ?? null;
        $rawMetaTitle = $body['meta_title'] ?? null;
        $rawMetaDescription = $body['meta_description'] ?? null;
        $translationId = UuidGenerator::v7();
        $translation = ContentTranslation::create(
            id: $translationId,
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $slugSegment,
            path: $path,
            body: $sanitizedBody,
            excerpt: is_string($rawExcerpt) ? $rawExcerpt : null,
            metaTitle: is_string($rawMetaTitle) ? $rawMetaTitle : null,
            metaDescription: is_string($rawMetaDescription) ? $rawMetaDescription : null,
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
        $lock = $this->lockService->getLockInfo($id);

        // Build locale availability for admin locale tabs
        $translatedLocales = array_map(
            static fn(ContentTranslation $t) => $t->locale,
            $translations,
        );
        $localeAvailability = [];

        foreach ($this->config->supportedLocales as $supportedLocale) {
            $localeAvailability[$supportedLocale] = in_array($supportedLocale, $translatedLocales, true);
        }

        $data = [
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
                'locked_at' => $lock->lockedAt->format('c'),
                'expires_at' => $lock->expiresAt->format('c'),
            ] : null,
        ];

        return $this->respondWithView($request, 'admin.content.show', $data);
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

        $rawLocale = $body['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : $this->config->defaultLocale;
        $translation = $this->translationRepository->findByContentAndLocale($id, $locale);

        if ($translation === null) {
            return Response::json(['error' => 'Translation not found for locale'], 404);
        }

        $rawTitle = $body['title'] ?? null;
        $title = is_string($rawTitle) ? $rawTitle : $translation->title;
        $rawBodyText = $body['body'] ?? null;
        $rawBody = is_string($rawBodyText) ? $rawBodyText : $translation->body;
        $sanitizedBody = $this->safeHtmlPolicy->sanitize($rawBody);
        $rawSlug = $body['slug'] ?? null;
        $slugSegment = is_string($rawSlug) ? $rawSlug : $translation->slugSegment;

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

        $rawExcerpt = $body['excerpt'] ?? null;
        $rawMetaTitle = $body['meta_title'] ?? null;
        $rawMetaDescription = $body['meta_description'] ?? null;
        $updatedTranslation = ContentTranslation::create(
            id: $translation->id,
            contentId: $id,
            locale: $locale,
            title: $title,
            slugSegment: $slugSegment,
            path: $path,
            body: $sanitizedBody,
            excerpt: is_string($rawExcerpt) ? $rawExcerpt : $translation->excerpt,
            metaTitle: is_string($rawMetaTitle) ? $rawMetaTitle : $translation->metaTitle,
            metaDescription: is_string($rawMetaDescription) ? $rawMetaDescription : $translation->metaDescription,
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

        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : '';

        if ($locale === '' || !in_array($locale, $this->config->supportedLocales, true)) {
            return Response::json(['error' => 'Invalid or unsupported locale'], 400);
        }

        // Check if translation already exists for this locale
        $existing = $this->translationRepository->findByContentAndLocale($id, $locale);

        if ($existing !== null) {
            return Response::json(['error' => 'Translation already exists for this locale'], 409);
        }

        $title = is_string($body['title'] ?? null) ? $body['title'] : '';
        $slugSegment = is_string($body['slug'] ?? null) ? $body['slug'] : '';
        $rawBody = is_string($body['body'] ?? null) ? $body['body'] : '';

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

        $translationId = UuidGenerator::v7();

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
        $publishAtStr = is_string($body['publish_at'] ?? null) ? $body['publish_at'] : '';

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
            // Content may already be in review: the review record still stands
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

    public function breakLock(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.admin');

        $this->lockService->forceUnlock($id, $identity->id());

        return Response::json(['success' => true]);
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : $this->config->defaultLocale;
    }

}
