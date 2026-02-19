<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Studio;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Studio\Dto\SeoHealthReport;
use Pulsar\Extension\Cms\Internal\Studio\SeoHealthReportPanel;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;

#[CoversClass(SeoHealthReportPanel::class)]
#[CoversClass(SeoHealthReport::class)]
final class SeoHealthReportPanelTest extends TestCase
{
    #[Test]
    public function test_generate_report_with_broken_links(): void
    {
        $brokenLink = new LinkHealthCheck(
            id: 'lhc-1',
            tenantId: null,
            sourceContentId: 'content-1',
            sourceLocale: 'en',
            targetUrl: 'https://example.com/broken',
            isBroken: true,
            isRedirected: false,
            httpStatusCode: 404,
            lastCheckedAt: new DateTimeImmutable(),
            createdAt: new DateTimeImmutable(),
        );

        $linkHealth = $this->createStub(LinkHealthServiceInterface::class);
        $linkHealth->method('getBrokenLinks')->willReturn([$brokenLink]);
        $linkHealth->method('getOrphanContent')->willReturn([]);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        $panel = new SeoHealthReportPanel($linkHealth, $contentRepo);

        $report = $panel->generateReport();

        self::assertSame(1, $report->brokenLinkCount);
        self::assertCount(1, $report->brokenLinks);
        self::assertSame('https://example.com/broken', $report->brokenLinks[0]->targetUrl);
        self::assertSame(0, $report->orphanContentCount);
    }

    #[Test]
    public function test_generate_report_with_orphan_content(): void
    {
        $orphan = new Content(
            id: 'content-orphan',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'user-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: new DateTimeImmutable(),
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
            version: 1,
        );

        $linkHealth = $this->createStub(LinkHealthServiceInterface::class);
        $linkHealth->method('getBrokenLinks')->willReturn([]);
        $linkHealth->method('getOrphanContent')->willReturn([$orphan]);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        $panel = new SeoHealthReportPanel($linkHealth, $contentRepo);

        $report = $panel->generateReport();

        self::assertSame(0, $report->brokenLinkCount);
        self::assertSame(1, $report->orphanContentCount);
        self::assertSame('content-orphan', $report->orphanContent[0]->id);
    }

    #[Test]
    public function test_generate_report_includes_sitemap_status(): void
    {
        $linkHealth = $this->createStub(LinkHealthServiceInterface::class);
        $linkHealth->method('getBrokenLinks')->willReturn([]);
        $linkHealth->method('getOrphanContent')->willReturn([]);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        $panel = new SeoHealthReportPanel($linkHealth, $contentRepo);

        $lastGenerated = new DateTimeImmutable('2026-02-15T10:00:00+00:00');

        $report = $panel->generateReport(
            sitemapLastGenerated: $lastGenerated,
            sitemapEntryCount: 250,
            sitemapErrors: ['Timeout on page generation'],
        );

        self::assertSame($lastGenerated, $report->sitemapLastGenerated);
        self::assertSame(250, $report->sitemapEntryCount);
        self::assertSame(['Timeout on page generation'], $report->sitemapErrors);
    }

    #[Test]
    public function test_generate_report_with_tenant_scope(): void
    {
        $linkHealth = $this->createMock(LinkHealthServiceInterface::class);
        $linkHealth->expects(self::once())
            ->method('getBrokenLinks')
            ->with('tenant-1', 1, 100)
            ->willReturn([]);
        $linkHealth->expects(self::once())
            ->method('getOrphanContent')
            ->with('tenant-1', 1, 100)
            ->willReturn([]);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        $panel = new SeoHealthReportPanel($linkHealth, $contentRepo);

        $report = $panel->generateReport(tenantId: 'tenant-1');

        self::assertSame(0, $report->brokenLinkCount);
        self::assertSame(0, $report->orphanContentCount);
    }

    #[Test]
    public function test_broken_link_count_shortcut(): void
    {
        $brokenLinks = [
            new LinkHealthCheck('1', null, 'c1', 'en', 'https://a.com', true, false, 404, new DateTimeImmutable(), new DateTimeImmutable()),
            new LinkHealthCheck('2', null, 'c2', 'en', 'https://b.com', true, false, 500, new DateTimeImmutable(), new DateTimeImmutable()),
        ];

        $linkHealth = $this->createStub(LinkHealthServiceInterface::class);
        $linkHealth->method('getBrokenLinks')->willReturn($brokenLinks);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        $panel = new SeoHealthReportPanel($linkHealth, $contentRepo);

        self::assertSame(2, $panel->brokenLinkCount());
    }

    #[Test]
    public function test_orphan_content_count_shortcut(): void
    {
        $orphan = new Content(
            id: 'orphan-1',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'user-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: new DateTimeImmutable(),
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
            version: 1,
        );

        $linkHealth = $this->createStub(LinkHealthServiceInterface::class);
        $linkHealth->method('getOrphanContent')->willReturn([$orphan]);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        $panel = new SeoHealthReportPanel($linkHealth, $contentRepo);

        self::assertSame(1, $panel->orphanContentCount());
    }

    #[Test]
    public function test_generate_report_empty_state(): void
    {
        $linkHealth = $this->createStub(LinkHealthServiceInterface::class);
        $linkHealth->method('getBrokenLinks')->willReturn([]);
        $linkHealth->method('getOrphanContent')->willReturn([]);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        $panel = new SeoHealthReportPanel($linkHealth, $contentRepo);

        $report = $panel->generateReport();

        self::assertSame(0, $report->brokenLinkCount);
        self::assertSame([], $report->brokenLinks);
        self::assertSame(0, $report->orphanContentCount);
        self::assertSame([], $report->orphanContent);
        self::assertNull($report->sitemapLastGenerated);
        self::assertSame(0, $report->sitemapEntryCount);
        self::assertSame([], $report->sitemapErrors);
    }
}
