<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Internal\Admin\AnalyticsAdminWidget;

final class AnalyticsAdminWidgetTest extends TestCase
{
    #[Test]
    public function get_summary_with_sites(): void
    {
        $site = new Site(
            id: 'site_1',
            domain: 'example.com',
            name: 'Example',
            trackingId: 'plsr_test1',
            timezone: 'UTC',
            createdAt: new DateTimeImmutable(),
        );

        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([$site]);

        $statsService = $this->createStub(StatsServiceInterface::class);
        $statsService->method('getAggregate')->willReturn(['visitors' => 150]);
        $statsService->method('getBreakdown')->willReturn([
            ['name' => '/home', 'visitors' => 80],
        ]);

        $widget = new AnalyticsAdminWidget($statsService, $siteRepo);
        $summary = $widget->getSummary();

        self::assertSame(150, $summary['visitors_today']);
        self::assertSame(1, $summary['sites_count']);
        self::assertSame('/home', $summary['top_page']);
    }

    #[Test]
    public function get_summary_with_no_sites(): void
    {
        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([]);

        $statsService = $this->createStub(StatsServiceInterface::class);

        $widget = new AnalyticsAdminWidget($statsService, $siteRepo);
        $summary = $widget->getSummary();

        self::assertSame(0, $summary['visitors_today']);
        self::assertSame(0, $summary['sites_count']);
        self::assertSame('', $summary['top_page']);
    }

    #[Test]
    public function get_summary_with_empty_breakdown(): void
    {
        $site = new Site(
            id: 'site_1',
            domain: 'empty.com',
            name: 'Empty',
            trackingId: 'plsr_empty1',
            timezone: 'UTC',
            createdAt: new DateTimeImmutable(),
        );

        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([$site]);

        $statsService = $this->createStub(StatsServiceInterface::class);
        $statsService->method('getAggregate')->willReturn(['visitors' => 0]);
        $statsService->method('getBreakdown')->willReturn([]);

        $widget = new AnalyticsAdminWidget($statsService, $siteRepo);
        $summary = $widget->getSummary();

        self::assertSame(0, $summary['visitors_today']);
        self::assertSame('', $summary['top_page']);
    }

    #[Test]
    public function get_summary_with_multiple_sites_aggregates_visitors(): void
    {
        $site1 = new Site(id: 's1', domain: 'a.com', name: 'A', trackingId: 'plsr_s1', timezone: 'UTC', createdAt: new DateTimeImmutable());
        $site2 = new Site(id: 's2', domain: 'b.com', name: 'B', trackingId: 'plsr_s2', timezone: 'UTC', createdAt: new DateTimeImmutable());

        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([$site1, $site2]);

        $statsService = $this->createStub(StatsServiceInterface::class);
        $statsService->method('getAggregate')->willReturnOnConsecutiveCalls(
            ['visitors' => 100],
            ['visitors' => 200],
        );
        $statsService->method('getBreakdown')->willReturnOnConsecutiveCalls(
            [['name' => '/a', 'visitors' => 50]],
            [['name' => '/b', 'visitors' => 120]],
        );

        $widget = new AnalyticsAdminWidget($statsService, $siteRepo);
        $summary = $widget->getSummary();

        self::assertSame(300, $summary['visitors_today']);
        self::assertSame(2, $summary['sites_count']);
        self::assertSame('/b', $summary['top_page']);
    }
}
