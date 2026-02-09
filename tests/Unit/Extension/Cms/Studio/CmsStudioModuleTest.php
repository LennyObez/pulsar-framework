<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Studio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Studio\CmsAuditPanel;
use Pulsar\Extension\Cms\Internal\Studio\CmsStudioModule;
use Pulsar\Extension\Cms\Internal\Studio\ContentCacheInspectorPanel;
use Pulsar\Extension\Cms\Internal\Studio\MediaProcessingQueuePanel;
use Pulsar\Extension\Cms\Internal\Studio\SeoHealthReportPanel;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Extension\Studio\Contracts\StudioModuleInterface;
use Pulsar\Extension\Studio\Contracts\StudioNavEntry;
use Pulsar\Extension\Studio\Internal\StudioModuleRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Crypto\KeyRingInterface;

#[CoversClass(CmsStudioModule::class)]
final class CmsStudioModuleTest extends TestCase
{
    private CmsStudioModule $module;

    protected function setUp(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $taggedCache = $this->createStub(TaggedCacheInterface::class);
        $metricRegistry = new MetricRegistry();
        $queueDriver = $this->createStub(QueueDriverInterface::class);
        $linkHealthService = $this->createStub(LinkHealthServiceInterface::class);
        $contentRepository = $this->createStub(ContentRepositoryInterface::class);

        $this->module = new CmsStudioModule(
            auditPanel: new CmsAuditPanel(new AuditChainVerifier($keyRing)),
            cachePanel: new ContentCacheInspectorPanel($taggedCache, $metricRegistry),
            mediaPanel: new MediaProcessingQueuePanel($queueDriver),
            seoPanel: new SeoHealthReportPanel($linkHealthService, $contentRepository),
        );
    }

    #[Test]
    public function test_implements_studio_module_interface(): void
    {
        self::assertInstanceOf(StudioModuleInterface::class, $this->module);
    }

    #[Test]
    public function test_module_id_is_cms(): void
    {
        self::assertSame('cms', $this->module->moduleId());
    }

    #[Test]
    public function test_label_returns_cms(): void
    {
        self::assertSame('CMS', $this->module->label());
    }

    #[Test]
    public function test_icon_returns_valid_icon_name(): void
    {
        self::assertSame('file-text', $this->module->icon());
    }

    #[Test]
    public function test_route_prefix(): void
    {
        self::assertSame('/studio/cms', $this->module->routePrefix());
    }

    #[Test]
    public function test_nav_order(): void
    {
        self::assertSame(60, $this->module->navOrder());
    }

    #[Test]
    public function test_nav_entries_returns_four_entries(): void
    {
        $entries = $this->module->navEntries();

        self::assertCount(4, $entries);
        self::assertContainsOnlyInstancesOf(StudioNavEntry::class, $entries);
    }

    #[Test]
    public function test_nav_entries_contain_audit_trail(): void
    {
        $entries = $this->module->navEntries();

        self::assertSame('Audit Trail', $entries[0]->label);
        self::assertSame('/studio/cms/audit', $entries[0]->href);
        self::assertSame('shield', $entries[0]->icon);
    }

    #[Test]
    public function test_nav_entries_contain_content_cache(): void
    {
        $entries = $this->module->navEntries();

        self::assertSame('Content Cache', $entries[1]->label);
        self::assertSame('/studio/cms/cache', $entries[1]->href);
        self::assertSame('database', $entries[1]->icon);
    }

    #[Test]
    public function test_nav_entries_contain_media_queue(): void
    {
        $entries = $this->module->navEntries();

        self::assertSame('Media Queue', $entries[2]->label);
        self::assertSame('/studio/cms/media-queue', $entries[2]->href);
        self::assertSame('image', $entries[2]->icon);
    }

    #[Test]
    public function test_nav_entries_contain_seo_health(): void
    {
        $entries = $this->module->navEntries();

        self::assertSame('SEO Health', $entries[3]->label);
        self::assertSame('/studio/cms/seo', $entries[3]->href);
        self::assertSame('search', $entries[3]->icon);
    }

    #[Test]
    public function test_nav_entries_are_ordered_sequentially(): void
    {
        $entries = $this->module->navEntries();

        $orders = array_map(static fn(StudioNavEntry $e): int => $e->order, $entries);

        self::assertSame([0, 1, 2, 3], $orders);
    }

    #[Test]
    public function test_can_register_with_studio_registry(): void
    {
        $registry = new StudioModuleRegistry();
        $registry->register($this->module);

        self::assertSame($this->module, $registry->get('cms'));
    }

    #[Test]
    public function test_panel_accessors_return_correct_instances(): void
    {
        self::assertInstanceOf(CmsAuditPanel::class, $this->module->auditPanel());
        self::assertInstanceOf(ContentCacheInspectorPanel::class, $this->module->cachePanel());
        self::assertInstanceOf(MediaProcessingQueuePanel::class, $this->module->mediaPanel());
        self::assertInstanceOf(SeoHealthReportPanel::class, $this->module->seoPanel());
    }
}
