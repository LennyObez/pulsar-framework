<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Studio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Studio\ContentCacheInspectorPanel;
use Pulsar\Extension\Cms\Internal\Studio\Dto\CacheInspectorEntry;
use Pulsar\Extension\Cms\Internal\Studio\Dto\CacheInspectorReport;
use Pulsar\Observability\Metrics\MetricRegistry;

#[CoversClass(ContentCacheInspectorPanel::class)]
#[CoversClass(CacheInspectorEntry::class)]
#[CoversClass(CacheInspectorReport::class)]
final class ContentCacheInspectorPanelTest extends TestCase
{
    #[Test]
    public function test_inspect_returns_hit_for_cached_content(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->method('get')
            ->willReturnCallback(static fn(string $key): ?string => $key === 'cms_page:abc-123' ? '<html>cached</html>' : null);

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        $report = $panel->inspect(['abc-123']);

        self::assertCount(1, $report->entries);
        self::assertTrue($report->entries[0]->isHit);
        self::assertSame('cms_page:abc-123', $report->entries[0]->cacheKey);
        self::assertSame('abc-123', $report->entries[0]->contentId);
    }

    #[Test]
    public function test_inspect_returns_miss_for_uncached_content(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->method('get')->willReturn(null);

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        $report = $panel->inspect(['missing-id']);

        self::assertCount(1, $report->entries);
        self::assertFalse($report->entries[0]->isHit);
    }

    #[Test]
    public function test_inspect_calculates_hit_rate_correctly(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->method('get')
            ->willReturnCallback(static fn(string $key): ?string => match ($key) {
                'cms_page:hit-1', 'cms_page:hit-2' => 'cached',
                default => null,
            });

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        $report = $panel->inspect(['hit-1', 'hit-2', 'miss-1']);

        self::assertSame(2, $report->totalHits);
        self::assertSame(1, $report->totalMisses);
        self::assertEqualsWithDelta(2 / 3, $report->hitRate, 0.001);
    }

    #[Test]
    public function test_inspect_empty_content_ids_returns_zero_rate(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        $report = $panel->inspect([]);

        self::assertSame([], $report->entries);
        self::assertSame(0.0, $report->hitRate);
        self::assertSame(0, $report->totalHits);
        self::assertSame(0, $report->totalMisses);
    }

    #[Test]
    public function test_get_metrics_hit_rate_with_no_metrics(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        self::assertSame(0.0, $panel->getMetricsHitRate());
    }

    #[Test]
    public function test_get_metrics_hit_rate_from_counters(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('cms_page_cache_hits_total')->increment(value: 80.0);
        $registry->counter('cms_page_cache_misses_total')->increment(value: 20.0);

        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $panel = new ContentCacheInspectorPanel($taggedCache, $registry);

        self::assertEqualsWithDelta(0.8, $panel->getMetricsHitRate(), 0.001);
    }

    #[Test]
    public function test_invalidate_by_content_id(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->expects(self::once())
            ->method('delete')
            ->with('cms_page:content-42');

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());
        $panel->invalidateByContentId('content-42');
    }

    #[Test]
    public function test_invalidate_by_tag(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->expects(self::once())
            ->method('invalidateTag')
            ->with('content_type:article');

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());
        $panel->invalidateByTag('content_type:article');
    }

    #[Test]
    public function test_flush_all(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_page');

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());
        $panel->flushAll();
    }
}
