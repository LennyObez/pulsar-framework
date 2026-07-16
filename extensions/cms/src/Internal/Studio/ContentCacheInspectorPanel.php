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
 * Allows viewing cached pages (keys matching "cms_page.*"),
 * displays cache hit rates from metrics, and provides manual
 * invalidation by content ID, by tag, or full flush.
 *
 * @psalm-api Resolved from the DI container by CmsStudioModule; not
 *            instantiated by name.
 */
#[Internal]
final readonly class ContentCacheInspectorPanel
{
    /** Cache key prefix for CMS page cache entries. */

    /** Metric name for cache hits. */
    private const string METRIC_CACHE_HITS = 'cms_page_cache_hits_total';

    /** Metric name for cache misses. */
    private const string METRIC_CACHE_MISSES = 'cms_page_cache_misses_total';

    public function __construct(
        private TaggedCacheInterface $taggedCache,
        private MetricRegistry $metricRegistry,
    ) {}

    /**
     * Inspect cached pages for the given content IDs.
     *
     * Checks each content ID's cache key against the tagged cache
     * and returns entries indicating hit/miss status.
     *
     * @param list<string> $contentIds Content IDs to inspect
     */
    public function inspect(array $contentIds): CacheInspectorReport
    {
        $entries = [];
        $hits = 0;
        $misses = 0;

        foreach ($contentIds as $contentId) {
            $cacheKey = CmsCacheKeys::PAGE_KEY_PREFIX . $contentId;
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
     * Invalidate cached page for a specific content ID.
     */
    public function invalidateByContentId(string $contentId): void
    {
        $this->taggedCache->delete(CmsCacheKeys::PAGE_KEY_PREFIX . $contentId);
    }

    /**
     * Invalidate all cached pages associated with a cache tag.
     */
    public function invalidateByTag(string $tag): void
    {
        $this->taggedCache->invalidateTag($tag);
    }

    /**
     * Flush all CMS page cache entries by invalidating the root tag.
     */
    public function flushAll(): void
    {
        $this->taggedCache->invalidateTag('cms_page');
    }
}
