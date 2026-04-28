<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Http\Message\Response;

use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_map;
use function explode;
use function is_int;
use function is_string;
use function max;
use function min;

/**
 * Public REST API controller for CMS content.
 *
 * Provides JSON endpoints for content listing, retrieval, creation, update,
 * and soft deletion. Authentication is handled by the CmsApiKeyMiddleware
 * in the middleware pipeline.
 */
#[Internal(reason: 'CMS REST API controller; implementation detail')]
final readonly class ContentApiController
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        private FieldRegistryRepositoryInterface $fieldRepository,
        private CmsConfig $config,
    ) {}

    /**
     * GET /api/v1/content: List content with pagination and filtering.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var mixed $rawLocale */
        $rawLocale = $params['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : $this->config->defaultLocale;
        /** @var mixed $rawType */
        $rawType = $params['type'] ?? null;
        $contentType = is_string($rawType) ? $rawType : null;
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 20));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->contentRepository->findPublished(
            $locale,
            $contentType,
            $page,
            $perPage,
            $tenantId,
        );

        /** @var array<string, mixed> $params */
        $fieldsFilter = $this->parseFieldsFilter($params);

        $data = array_map(
            fn(Content $c) => $this->serializeContentSummary($c, $fieldsFilter),
            $result->items,
        );

        return Response::json(['data' => $data, 'pagination' => $result->metaToArray()])
            ->withHeader('X-Total-Count', (string) ($result->total ?? 0))
            ->withHeader('X-Page', (string) $page)
            ->withHeader('X-Per-Page', (string) $perPage);
    }

    /**
     * GET /api/v1/content/{id}: Show a single content item with all related data.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        /** @var mixed $rawLocale */
        $rawLocale = $params['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : $this->config->defaultLocale;

        $translations = $this->translationRepository->findByContentId($id);
        $blocks = $this->blockRepository->findByContentAndLocale($id, $locale);
        $customFields = $this->fieldRepository->findValues($id, $locale);

        return Response::json([
            'data' => [
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
                    'body' => $t->body,
                    'excerpt' => $t->excerpt,
                    'meta_title' => $t->metaTitle,
                    'meta_description' => $t->metaDescription,
                ], $translations),
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
            ],
        ]);
    }

    /**
     * POST /api/v1/content: Create new content from JSON body.
     */
    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $errors = $this->validateCreatePayload($body);

        if ($errors !== []) {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => $errors,
            ], 422);
        }

        /** @var mixed $rawContentTypeValue */
        $rawContentTypeValue = $body['content_type'] ?? null;
        $contentTypeStr = is_string($rawContentTypeValue) ? $rawContentTypeValue : 'page';
        $contentType = ContentType::tryFrom($contentTypeStr);

        if ($contentType === null) {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['content_type' => 'Invalid content type'],
            ], 422);
        }

        /** @var mixed $rawLocaleBody */
        $rawLocaleBody = $body['locale'] ?? null;
        $locale = is_string($rawLocaleBody) ? $rawLocaleBody : $this->config->defaultLocale;
        /** @var mixed $rawTitle */
        $rawTitle = $body['title'] ?? null;
        $title = is_string($rawTitle) ? $rawTitle : '';
        /** @var mixed $rawSlug */
        $rawSlug = $body['slug'] ?? null;
        $slugSegment = is_string($rawSlug) ? $rawSlug : '';
        /** @var mixed $rawBody */
        $rawBody = $body['body'] ?? null;
        $bodyContent = is_string($rawBody) ? $rawBody : '';

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        /** @var mixed $rawAuthorId */
        $rawAuthorId = $body['author_id'] ?? null;
        /** @var mixed $rawTemplate */
        $rawTemplate = $body['template'] ?? null;
        /** @var mixed $rawParentId */
        $rawParentId = $body['parent_id'] ?? null;
        $contentId = UuidGenerator::v7();
        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: is_string($rawAuthorId) ? $rawAuthorId : 'api',
            tenantId: $tenantId,
            template: is_string($rawTemplate) ? $rawTemplate : null,
            parentId: is_string($rawParentId) ? $rawParentId : null,
        );

        $this->contentRepository->save($content);

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
                contentId: $contentId,
                locale: $locale,
                title: $title,
                slugSegment: $slugSegment,
                path: $path,
                body: $bodyContent,
                excerpt: is_string($rawExcerpt = $body['excerpt'] ?? null) ? $rawExcerpt : null,
                metaTitle: is_string($rawMetaTitle = $body['meta_title'] ?? null) ? $rawMetaTitle : null,
                metaDescription: is_string($rawMetaDescription = $body['meta_description'] ?? null) ? $rawMetaDescription : null,
            );
        } catch (CmsException $e) {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['slug' => $e->getMessage()],
            ], 422);
        }

        $this->translationRepository->save($translation);

        return Response::json([
            'data' => [
                'id' => $contentId,
                'status' => $content->status->value,
            ],
        ], 201);
    }

    /**
     * PUT /api/v1/content/{id}: Update existing content.
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found', 'status' => 404], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawLocale */
        $rawLocale = $body['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : $this->config->defaultLocale;
        $translation = $this->translationRepository->findByContentAndLocale($id, $locale);

        if ($translation === null) {
            return Response::json(['error' => 'Translation not found for locale', 'status' => 404], 404);
        }

        /** @var mixed $rawTitle */
        $rawTitle = $body['title'] ?? null;
        $title = is_string($rawTitle) ? $rawTitle : $translation->title;
        /** @var mixed $rawBody */
        $rawBody = $body['body'] ?? null;
        $bodyContent = is_string($rawBody) ? $rawBody : $translation->body;
        /** @var mixed $rawSlug */
        $rawSlug = $body['slug'] ?? null;
        $slugSegment = is_string($rawSlug) ? $rawSlug : $translation->slugSegment;

        $path = $translation->path;

        if ($slugSegment !== $translation->slugSegment) {
            if ($content->parentId !== null) {
                $parentTranslation = $this->translationRepository->findByContentAndLocale($content->parentId, $locale);
                $path = $parentTranslation !== null
                    ? $parentTranslation->path . '/' . $slugSegment
                    : $slugSegment;
            } else {
                $path = $slugSegment;
            }
        }

        try {
            $updatedTranslation = ContentTranslation::create(
                id: $translation->id,
                contentId: $id,
                locale: $locale,
                title: $title,
                slugSegment: $slugSegment,
                path: $path,
                body: $bodyContent,
                excerpt: is_string($rawExcerpt = $body['excerpt'] ?? null) ? $rawExcerpt : $translation->excerpt,
                metaTitle: is_string($rawMetaTitle = $body['meta_title'] ?? null) ? $rawMetaTitle : $translation->metaTitle,
                metaDescription: is_string($rawMetaDescription = $body['meta_description'] ?? null) ? $rawMetaDescription : $translation->metaDescription,
            );
        } catch (CmsException $e) {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['slug' => $e->getMessage()],
            ], 422);
        }

        $this->translationRepository->save($updatedTranslation);

        return Response::json([
            'data' => ['id' => $id, 'status' => 'updated'],
        ]);
    }

    /**
     * DELETE /api/v1/content/{id}: Soft delete content.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return Response::json(['error' => 'Content not found', 'status' => 404], 404);
        }

        $this->contentRepository->delete($content);

        return Response::json([
            'data' => ['id' => $id, 'status' => 'deleted'],
        ]);
    }

    /**
     * Parse the ?fields=id,title,body query parameter into a key set.
     *
     * @param array<string, mixed> $params
     * @return list<string>|null Null means return all fields
     */
    private function parseFieldsFilter(array $params): ?array
    {
        $fields = $params['fields'] ?? null;

        if (!is_string($fields) || $fields === '') {
            return null;
        }

        return array_values(array_filter(explode(',', $fields), static fn(string $f) => $f !== ''));
    }

    /**
     * Serialize a content item summary, optionally filtering to requested fields.
     *
     * @param list<string>|null $fieldsFilter
     * @return array<string, mixed>
     */
    private function serializeContentSummary(Content $content, ?array $fieldsFilter): array
    {
        $full = [
            'id' => $content->id,
            'type' => $content->contentType->value,
            'status' => $content->status->value,
            'author_id' => $content->authorId,
            'published_at' => $content->publishedAt?->format('c'),
            'created_at' => $content->createdAt->format('c'),
            'updated_at' => $content->updatedAt->format('c'),
        ];

        if ($fieldsFilter === null) {
            return $full;
        }

        return array_intersect_key($full, array_flip($fieldsFilter));
    }

    /**
     * Validate create request payload.
     *
     * @param array<string, mixed> $body
     * @return array<string, string> Field name => error message
     */
    private function validateCreatePayload(array $body): array
    {
        $errors = [];

        if (!is_string($body['title'] ?? null) || ($body['title'] ?? '') === '') {
            $errors['title'] = 'Title is required';
        }

        if (!is_string($body['slug'] ?? null) || ($body['slug'] ?? '') === '') {
            $errors['slug'] = 'Slug is required';
        }

        return $errors;
    }
}
