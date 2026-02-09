<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Dashboard;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Dashboard\SeoHealthWidget;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;

#[CoversClass(SeoHealthWidget::class)]
final class SeoHealthWidgetTest extends TestCase
{
    #[Test]
    public function test_get_name_returns_seo_health(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $widget = new SeoHealthWidget($service);

        self::assertSame('seo_health', $widget->getName());
    }

    #[Test]
    public function test_get_template_returns_expected_path(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $widget = new SeoHealthWidget($service);

        self::assertSame('dashboard/widgets/seo-health', $widget->getTemplate());
    }

    #[Test]
    public function test_get_data_healthy_when_no_broken_links_and_sitemap_enabled(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('getBrokenLinks')->willReturn([]);

        $lastGen = new DateTimeImmutable('2026-02-19T10:00:00+00:00');
        $widget = new SeoHealthWidget($service, $lastGen, true);
        $data = $widget->getData();

        self::assertSame(0, $data['broken_link_count']);
        self::assertTrue($data['sitemap_enabled']);
        self::assertSame('2026-02-19T10:00:00+00:00', $data['last_sitemap_generation']);
        self::assertSame('healthy', $data['health_status']);
    }

    #[Test]
    public function test_get_data_degraded_when_few_broken_links(): void
    {
        $brokenLinks = [];
        for ($i = 0; $i < 5; $i++) {
            $brokenLinks[] = $this->createBrokenLink();
        }

        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('getBrokenLinks')->willReturn($brokenLinks);

        $widget = new SeoHealthWidget($service, sitemapEnabled: true);
        $data = $widget->getData();

        self::assertSame(5, $data['broken_link_count']);
        self::assertSame('degraded', $data['health_status']);
    }

    #[Test]
    public function test_get_data_unhealthy_when_many_broken_links(): void
    {
        $brokenLinks = [];
        for ($i = 0; $i < 15; $i++) {
            $brokenLinks[] = $this->createBrokenLink();
        }

        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('getBrokenLinks')->willReturn($brokenLinks);

        $widget = new SeoHealthWidget($service, sitemapEnabled: true);
        $data = $widget->getData();

        self::assertSame(15, $data['broken_link_count']);
        self::assertSame('unhealthy', $data['health_status']);
    }

    #[Test]
    public function test_get_data_unhealthy_when_sitemap_disabled(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('getBrokenLinks')->willReturn([]);

        $widget = new SeoHealthWidget($service, sitemapEnabled: false);
        $data = $widget->getData();

        self::assertSame('unhealthy', $data['health_status']);
    }

    #[Test]
    public function test_get_data_null_sitemap_generation_when_not_set(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('getBrokenLinks')->willReturn([]);

        $widget = new SeoHealthWidget($service);
        $data = $widget->getData();

        self::assertNull($data['last_sitemap_generation']);
    }

    #[Test]
    public function test_get_data_passes_tenant_id(): void
    {
        $tenantId = '01912345-6789-7abc-8def-000000000001';

        $service = $this->createMock(LinkHealthServiceInterface::class);
        $service->expects(self::once())
            ->method('getBrokenLinks')
            ->with($tenantId, 1, 100)
            ->willReturn([]);

        $widget = new SeoHealthWidget($service, tenantId: $tenantId);
        $widget->getData();
    }

    private function createBrokenLink(): LinkHealthCheck
    {
        $now = new DateTimeImmutable();

        return new LinkHealthCheck(
            id: '01912345-6789-7abc-8def-' . bin2hex(random_bytes(6)),
            tenantId: null,
            sourceContentId: '01912345-6789-7abc-8def-0123456789ab',
            sourceLocale: 'en',
            targetUrl: 'https://example.com/broken-' . bin2hex(random_bytes(4)),
            isBroken: true,
            isRedirected: false,
            httpStatusCode: 404,
            lastCheckedAt: $now,
            createdAt: $now,
        );
    }
}
