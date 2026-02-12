<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Dashboard\ContentStatusQueryInterface;
use Pulsar\Extension\Cms\Dashboard\ContentStatusWidget;

#[CoversClass(ContentStatusWidget::class)]
final class ContentStatusWidgetTest extends TestCase
{
    #[Test]
    public function getNameReturnsContentStatus(): void
    {
        $query = $this->createStub(ContentStatusQueryInterface::class);
        $widget = new ContentStatusWidget($query);

        self::assertSame('content_status', $widget->getName());
    }

    #[Test]
    public function getTemplateReturnsExpectedPath(): void
    {
        $query = $this->createStub(ContentStatusQueryInterface::class);
        $widget = new ContentStatusWidget($query);

        self::assertSame('dashboard/widgets/content-status', $widget->getTemplate());
    }

    #[Test]
    public function getDataReturnsCountsSparklineAndTotal(): void
    {
        $statusCounts = [
            'draft' => 5,
            'published' => 20,
            'scheduled' => 3,
            'archived' => 2,
        ];

        $trendData = [
            ['date' => '2026-02-05', 'count' => 2],
            ['date' => '2026-02-06', 'count' => 3],
        ];

        $query = $this->createMock(ContentStatusQueryInterface::class);
        $query->expects(self::once())
            ->method('countByStatus')
            ->with(null)
            ->willReturn($statusCounts);
        $query->expects(self::once())
            ->method('getDailyCreationTrend')
            ->with(14, null)
            ->willReturn($trendData);

        $widget = new ContentStatusWidget($query);
        $data = $widget->getData();

        self::assertSame($statusCounts, $data['counts']);
        self::assertSame($trendData, $data['sparkline']);
        self::assertSame(30, $data['total']);
    }

    #[Test]
    public function getDataPassesTenantId(): void
    {
        $tenantId = '01912345-6789-7abc-8def-000000000001';

        $query = $this->createMock(ContentStatusQueryInterface::class);
        $query->expects(self::once())
            ->method('countByStatus')
            ->with($tenantId)
            ->willReturn(['draft' => 1]);
        $query->expects(self::once())
            ->method('getDailyCreationTrend')
            ->with(14, $tenantId)
            ->willReturn([]);

        $widget = new ContentStatusWidget($query, $tenantId);
        $widget->getData();
    }

    #[Test]
    public function getDataWithEmptyCountsReturnsZeroTotal(): void
    {
        $query = $this->createStub(ContentStatusQueryInterface::class);
        $query->method('countByStatus')->willReturn([]);
        $query->method('getDailyCreationTrend')->willReturn([]);

        $widget = new ContentStatusWidget($query);
        $data = $widget->getData();

        self::assertSame(0, $data['total']);
        self::assertSame([], $data['counts']);
        self::assertSame([], $data['sparkline']);
    }

    #[Test]
    public function implementsDashboardWidgetInterface(): void
    {
        $query = $this->createStub(ContentStatusQueryInterface::class);
        $widget = new ContentStatusWidget($query);

        self::assertInstanceOf(\Pulsar\Extension\Cms\Dashboard\DashboardWidgetInterface::class, $widget);
    }
}
