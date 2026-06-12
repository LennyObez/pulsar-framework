<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Resolver;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Resolves GraphQL queries for Content, Translation, and ContentBlock types.
 * @api
 */
#[Api(since: '1.0.0')]
readonly class ContentResolver
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
    ) {}

    /**
     * Resolve a single content item by ID.
     *
     * @return array<string, mixed>|null
     */
    public function resolveById(string $id): ?array
    {
        $content = $this->contentRepository->findById($id);

        if ($content === null) {
            return null;
        }

        return [
            'id' => $content->id,
            'tenantId' => $content->tenantId,
            'contentType' => $content->contentType->value,
            'authorId' => $content->authorId,
            'status' => $content->status->value,
            'template' => $content->template,
            'parentId' => $content->parentId,
            'sortOrder' => $content->sortOrder,
            'commentPolicy' => $content->commentPolicy->value,
            'publishedAt' => $content->publishedAt?->format('c'),
            'createdAt' => $content->createdAt->format('c'),
            'updatedAt' => $content->updatedAt->format('c'),
        ];
    }

    /**
     * Resolve paginated published content.
     *
     * @return array<string, mixed>
     */
    public function resolveList(string $locale, ?string $type, int $page, int $perPage): array
    {
        $result = $this->contentRepository->findPublished($locale, $type, $page, $perPage);

        $items = [];

        foreach ($result->items as $content) {
            $items[] = [
                'id' => $content->id,
                'tenantId' => $content->tenantId,
                'contentType' => $content->contentType->value,
                'authorId' => $content->authorId,
                'status' => $content->status->value,
                'template' => $content->template,
                'parentId' => $content->parentId,
                'sortOrder' => $content->sortOrder,
                'commentPolicy' => $content->commentPolicy->value,
                'publishedAt' => $content->publishedAt?->format('c'),
                'createdAt' => $content->createdAt->format('c'),
                'updatedAt' => $content->updatedAt->format('c'),
            ];
        }

        return [
            'items' => $items,
            'totalCount' => $result->total ?? 0,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Resolve translations for a content item.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveTranslations(string $contentId): array
    {
        $translations = $this->translationRepository->findByContentId($contentId);
        $result = [];

        foreach ($translations as $t) {
            $result[] = [
                'id' => $t->id,
                'contentId' => $t->contentId,
                'locale' => $t->locale,
                'title' => $t->title,
                'slugSegment' => $t->slugSegment,
                'path' => $t->path,
                'body' => $t->body,
                'excerpt' => $t->excerpt,
                'metaTitle' => $t->metaTitle,
                'metaDescription' => $t->metaDescription,
                'readingTimeMinutes' => $t->readingTimeMinutes,
            ];
        }

        return $result;
    }

    /**
     * Resolve content blocks for a content item in a locale.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveBlocks(string $contentId, string $locale): array
    {
        $blocks = $this->blockRepository->findByContentAndLocale($contentId, $locale);
        $result = [];

        foreach ($blocks as $b) {
            $result[] = [
                'id' => $b->id,
                'contentId' => $b->contentId,
                'locale' => $b->locale,
                'blockType' => $b->blockType,
                'sortOrder' => $b->sortOrder,
                'data' => json_encode($b->data, JSON_THROW_ON_ERROR),
            ];
        }

        return $result;
    }
}
