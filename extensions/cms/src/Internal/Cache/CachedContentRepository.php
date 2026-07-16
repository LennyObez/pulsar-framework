<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Cache;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStatus;

/**
 * @psalm-api Caching decorator wrapping the underlying ContentRepositoryInterface
 *            implementation; bound by the CMS service provider, not instantiated by name.
 */
#[Internal(reason: 'Caching decorator for content; use ContentRepositoryInterface')]
final readonly class CachedContentRepository implements ContentRepositoryInterface
{
    private const int TTL = 60;

    public function __construct(
        private ContentRepositoryInterface $inner,
        private TaggedCacheInterface $cache,
    ) {}

    #[Override]
    public function findById(string $id): ?Content
    {
        $cacheKey = CmsCacheKeys::contentId($id);
        $tag = CmsCacheKeys::contentTag($id);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var Content $cached */
            return $cached;
        }

        $content = $this->inner->findById($id);

        if ($content !== null) {
            $this->cache->set($cacheKey, $content, [$tag, CmsCacheKeys::TAG_ALL_CONTENT], self::TTL);
        }

        return $content;
    }

    #[Override]
    public function findByImportId(string $importId): ?Content
    {
        return $this->inner->findByImportId($importId);
    }

    #[Override]
    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?Content
    {
        $cacheKey = CmsCacheKeys::contentPath($locale, $path, $tenantId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var Content $cached */
            return $cached;
        }

        $content = $this->inner->findByPath($locale, $path, $tenantId);

        if ($content !== null) {
            $tag = CmsCacheKeys::contentTag($content->id);
            $this->cache->set($cacheKey, $content, [$tag, CmsCacheKeys::TAG_ALL_CONTENT], self::TTL);
        }

        return $content;
    }

    #[Override]
    public function findPublished(
        string $locale,
        ?string $contentType = null,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult {
        return $this->inner->findPublished($locale, $contentType, $page, $perPage, $tenantId);
    }

    #[Override]
    public function findByIds(array $ids): array
    {
        return $this->inner->findByIds($ids);
    }

    #[Override]
    public function findAncestors(string $contentId, int $maxDepth = 20): array
    {
        return $this->inner->findAncestors($contentId, $maxDepth);
    }

    #[Override]
    public function save(Content $content): void
    {
        $this->inner->save($content);
        $this->cache->invalidateTag(CmsCacheKeys::contentTag($content->id));
    }

    #[Override]
    public function delete(Content $content): void
    {
        $this->inner->delete($content);
        $this->cache->invalidateTag(CmsCacheKeys::contentTag($content->id));
    }

    #[Override]
    public function findDescendants(string $contentId): array
    {
        return $this->inner->findDescendants($contentId);
    }

    #[Override]
    public function findScheduledForPublishing(DateTimeImmutable $now): array
    {
        return $this->inner->findScheduledForPublishing($now);
    }

    #[Override]
    public function findScheduledForUnpublishing(DateTimeImmutable $now): array
    {
        return $this->inner->findScheduledForUnpublishing($now);
    }

    #[Override]
    public function bulkUpdateStatus(array $ids, PublishingStatus $status, ?string $tenantId = null): int
    {
        $affected = $this->inner->bulkUpdateStatus($ids, $status, $tenantId);
        $this->cache->invalidateTag(CmsCacheKeys::TAG_ALL_CONTENT);

        return $affected;
    }

    #[Override]
    public function bulkDelete(array $ids, ?string $tenantId = null): int
    {
        $affected = $this->inner->bulkDelete($ids, $tenantId);
        $this->cache->invalidateTag(CmsCacheKeys::TAG_ALL_CONTENT);

        return $affected;
    }
}
