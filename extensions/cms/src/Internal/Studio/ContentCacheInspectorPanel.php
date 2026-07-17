<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;
use Pulsar\Extension\Cms\Internal\Studio\Dto\CacheInspectorEntry;
use Pulsar\Extension\Cms\Internal\Studio\Dto\CacheInspectorReport;
use Pulsar\Observability\Metrics\MetricRegistry;

/**
 * Studio panel data provider for content cache inspection.
 *
 * Inspects the CONTENT cache entries (cms_content_id.*) — page-cache entries
 * are keyed by URL identity (tenant, locale, host/path digest) and are not
 * addressable by content ID, so per-content page inspection is impossible by
 * construction; page invalidation goes through tags instead. Also displays
 * cache hit rates from metrics and provides manual invalidation by content ID
 * (tag-based, reaching both the content entry and every page that rendered
 * it), by tag, or full flush.
 *
 * @psalm-api Resolved from the DI container by CmsStudioModule; not
 *            instantiated by name.
 */
#[Internal]
final readonly class ContentCacheInspectorPanel
{
    /** Metric name for cache hits. */
    private const string METRIC_CACHE_HITS = 'cms_page_cache_hits_total';

    /** Metric name for cache misses. */
    private const string METRIC_CACHE_MISSES = 'cms_page_cache_misses_total';

    public function __construct(
        private TaggedCacheInterface $taggedCache,
        private MetricRegistry $metricRegistry,
    ) {}

    /**
     * Inspect the content cache entries for the given content IDs.
     *
     * @param list<string> $contentIds Content IDs to inspect
     */
    public function inspect(array $contentIds): CacheInspectorReport
    {
        $entries = [];
        $hits = 0;
        $misses = 0;

        foreach ($contentIds as $contentId) {
            $cacheKey = CmsCacheKeys::contentId($contentId);
            $isHit = $this->taggedCache->get($cacheKey) !== null;

            $entries[] = new CacheInspectorEntry(
                cacheKey: $cacheKey,
                contentId: $contentId,
                isHit: $isHit,
            );

            if ($isHit) {
                $hits++;
            } else {
                $misses++;
            }
        }

        $total = $hits + $misses;
        $hitRate = $total > 0 ? (float) $hits / (float) $total : 0.0;

        return new CacheInspectorReport(
            entries: $entries,
            hitRate: $hitRate,
            totalHits: $hits,
            totalMisses: $misses,
        );
    }

    /**
     * Get aggregated cache hit rate from metrics.
     *
     * Returns the overall hit rate computed from the MetricRegistry
     * counters for CMS page cache hits and misses.
     */
    public function getMetricsHitRate(): float
    {
        $hitsCounter = $this->metricRegistry->has(self::METRIC_CACHE_HITS)
            ? $this->metricRegistry->counter(self::METRIC_CACHE_HITS)
            : null;

        $missesCounter = $this->metricRegistry->has(self::METRIC_CACHE_MISSES)
            ? $this->metricRegistry->counter(self::METRIC_CACHE_MISSES)
            : null;

        $totalHits = $hitsCounter !== null ? $hitsCounter->value() : 0.0;
        $totalMisses = $missesCounter !== null ? $missesCounter->value() : 0.0;
        $total = $totalHits + $totalMisses;

        return $total > 0.0 ? $totalHits / $total : 0.0;
    }

    /**
     * Invalidate everything a content ID touched: its cached content entry and
     * every page entry tagged as having rendered it. The previous direct
     * delete targeted a key format that never existed, so it deleted nothing.
     */
    public function invalidateByContentId(string $contentId): void
    {
        $this->taggedCache->invalidateTag(CmsCacheKeys::contentTag($contentId));
    }

    /**
     * Invalidate all cached pages associated with a cache tag.
     */
    public function invalidateByTag(string $tag): void
    {
        $this->taggedCache->invalidateTag($tag);
    }

    /**
     * Flush all CMS page and content cache entries via their coarse tags.
     * (The previous 'cms_page' tag was attached to nothing, so this was a
     * no-op.)
     */
    public function flushAll(): void
    {
        $this->taggedCache->invalidateTags([CmsCacheKeys::TAG_ALL_PAGES, CmsCacheKeys::TAG_ALL_CONTENT]);
    }
}
