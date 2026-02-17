<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\AggregationServiceInterface;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\DailyStats;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Internal\Scheduler\AggregationJob;

final class AggregationJobDailyCheckTest extends TestCase
{
    #[Test]
    public function runsDailyAggregationWhenStatsAreMissing(): void
    {
        $site = new Site(
            id: 'site_1',
            domain: 'example.com',
            name: 'Example',
            trackingId: 'plsr_x1',
            timezone: 'UTC',
            createdAt: new DateTimeImmutable(),
        );

        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([$site]);

        $dailyStatsRepo = $this->createStub(DailyStatsRepositoryInterface::class);
        $dailyStatsRepo->method('findByDateRange')->willReturn([]);

        /** @var AggregationServiceInterface&MockObject $aggregation */
        $aggregation = $this->createMock(AggregationServiceInterface::class);
        $aggregation->expects(self::once())->method('aggregateHourly');
        $aggregation->expects(self::once())->method('aggregateDaily');

        $job = new AggregationJob($aggregation, $siteRepo, $dailyStatsRepo);
        $job();
    }

    #[Test]
    public function skipsDailyAggregationWhenStatsExist(): void
    {
        $site = new Site(
            id: 'site_1',
            domain: 'example.com',
            name: 'Example',
            trackingId: 'plsr_x1',
            timezone: 'UTC',
            createdAt: new DateTimeImmutable(),
        );

        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([$site]);

        $existingStats = new DailyStats(
            siteId: 'site_1',
            date: new DateTimeImmutable('yesterday'),
            visitors: 100,
            pageviews: 500,
            sessions: 80,
            bounceRate: 45.0,
            avgDuration: 120.0,
            eventsCount: 50,
        );

        $dailyStatsRepo = $this->createStub(DailyStatsRepositoryInterface::class);
        $dailyStatsRepo->method('findByDateRange')->willReturn([$existingStats]);

        /** @var AggregationServiceInterface&MockObject $aggregation */
        $aggregation = $this->createMock(AggregationServiceInterface::class);
        $aggregation->expects(self::once())->method('aggregateHourly');
        $aggregation->expects(self::never())->method('aggregateDaily');

        $job = new AggregationJob($aggregation, $siteRepo, $dailyStatsRepo);
        $job();
    }

    #[Test]
    public function runsDailyForMultipleSitesIndependently(): void
    {
        $site1 = new Site(id: 'site_1', domain: 'a.com', name: 'A', trackingId: 'plsr_a1', timezone: 'UTC', createdAt: new DateTimeImmutable());
        $site2 = new Site(id: 'site_2', domain: 'b.com', name: 'B', trackingId: 'plsr_b2', timezone: 'UTC', createdAt: new DateTimeImmutable());

        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([$site1, $site2]);

        $existingStats = new DailyStats(
            siteId: 'site_1',
            date: new DateTimeImmutable('yesterday'),
            visitors: 10,
            pageviews: 50,
            sessions: 8,
            bounceRate: 40.0,
            avgDuration: 100.0,
            eventsCount: 5,
        );

        $dailyStatsRepo = $this->createStub(DailyStatsRepositoryInterface::class);
        $dailyStatsRepo->method('findByDateRange')->willReturnCallback(
            static function (string $siteId) use ($existingStats): array {
                // site_1 has stats, site_2 does not
                return $siteId === 'site_1' ? [$existingStats] : [];
            },
        );

        /** @var AggregationServiceInterface&MockObject $aggregation */
        $aggregation = $this->createMock(AggregationServiceInterface::class);
        $aggregation->expects(self::exactly(2))->method('aggregateHourly');
        // Only site_2 should get daily aggregation
        $aggregation->expects(self::once())->method('aggregateDaily');

        $job = new AggregationJob($aggregation, $siteRepo, $dailyStatsRepo);
        $job();
    }

    #[Test]
    public function noSitesDoesNothing(): void
    {
        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([]);

        $dailyStatsRepo = $this->createStub(DailyStatsRepositoryInterface::class);

        /** @var AggregationServiceInterface&MockObject $aggregation */
        $aggregation = $this->createMock(AggregationServiceInterface::class);
        $aggregation->expects(self::never())->method('aggregateHourly');
        $aggregation->expects(self::never())->method('aggregateDaily');

        $job = new AggregationJob($aggregation, $siteRepo, $dailyStatsRepo);
        $job();
    }
}
