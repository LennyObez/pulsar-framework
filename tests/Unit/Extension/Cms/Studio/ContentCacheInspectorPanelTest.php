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
    public function inspectReturnsHitForCachedContent(): void
    {
        $taggedCache = $this->createStub(TaggedCacheInterface::class);
        $taggedCache->method('get')
            ->willReturnCallback(static fn(string $key): ?string => $key === 'cms_content_id.abc-123' ? '<html>cached</html>' : null);

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        $report = $panel->inspect(['abc-123']);

        self::assertCount(1, $report->entries);
        self::assertTrue($report->entries[0]->isHit);
        self::assertSame('cms_content_id.abc-123', $report->entries[0]->cacheKey);
        self::assertSame('abc-123', $report->entries[0]->contentId);
    }

    #[Test]
    public function inspectReturnsMissForUncachedContent(): void
    {
        $taggedCache = $this->createStub(TaggedCacheInterface::class);
        $taggedCache->method('get')->willReturn(null);

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        $report = $panel->inspect(['missing-id']);

        self::assertCount(1, $report->entries);
        self::assertFalse($report->entries[0]->isHit);
    }

    #[Test]
    public function inspectCalculatesHitRateCorrectly(): void
    {
        $taggedCache = $this->createStub(TaggedCacheInterface::class);
        $taggedCache->method('get')
            ->willReturnCallback(static fn(string $key): ?string => match ($key) {
                'cms_content_id.hit-1', 'cms_content_id.hit-2' => 'cached',
                default => null,
            });

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        $report = $panel->inspect(['hit-1', 'hit-2', 'miss-1']);

        self::assertSame(2, $report->totalHits);
        self::assertSame(1, $report->totalMisses);
        self::assertEqualsWithDelta(2 / 3, $report->hitRate, 0.001);
    }

    #[Test]
    public function inspectEmptyContentIdsReturnsZeroRate(): void
    {
        $taggedCache = $this->createStub(TaggedCacheInterface::class);
        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        $report = $panel->inspect([]);

        self::assertSame([], $report->entries);
        self::assertSame(0.0, $report->hitRate);
        self::assertSame(0, $report->totalHits);
        self::assertSame(0, $report->totalMisses);
    }

    #[Test]
    public function getMetricsHitRateWithNoMetrics(): void
    {
        $taggedCache = $this->createStub(TaggedCacheInterface::class);
        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());

        self::assertSame(0.0, $panel->getMetricsHitRate());
    }

    #[Test]
    public function getMetricsHitRateFromCounters(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('cms_page_cache_hits_total')->increment(value: 80.0);
        $registry->counter('cms_page_cache_misses_total')->increment(value: 20.0);

        $taggedCache = $this->createStub(TaggedCacheInterface::class);
        $panel = new ContentCacheInspectorPanel($taggedCache, $registry);

        self::assertEqualsWithDelta(0.8, $panel->getMetricsHitRate(), 0.001);
    }

    #[Test]
    public function invalidateByContentId(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_content.content-42');

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());
        $panel->invalidateByContentId('content-42');
    }

    #[Test]
    public function invalidateByTag(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->expects(self::once())
            ->method('invalidateTag')
            ->with('content_type:article');

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());
        $panel->invalidateByTag('content_type:article');
    }

    #[Test]
    public function flushAll(): void
    {
        $taggedCache = $this->createMock(TaggedCacheInterface::class);
        $taggedCache->expects(self::once())
            ->method('invalidateTags')
            ->with(['cms_pages', 'cms_content']);

        $panel = new ContentCacheInspectorPanel($taggedCache, new MetricRegistry());
        $panel->flushAll();
    }
}
