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
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Internal\Scheduler\AggregationJob;

final class AggregationJobTest extends TestCase
{
    #[Test]
    public function invokes_hourly_aggregation_for_each_site(): void
    {
        $site1 = new Site(id: 'site_1', domain: 'a.com', name: 'A', trackingId: 'plsr_a1', timezone: 'UTC', createdAt: new DateTimeImmutable());
        $site2 = new Site(id: 'site_2', domain: 'b.com', name: 'B', trackingId: 'plsr_b2', timezone: 'UTC', createdAt: new DateTimeImmutable());

        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([$site1, $site2]);

        /** @var AggregationServiceInterface&MockObject $aggregation */
        $aggregation = $this->createMock(AggregationServiceInterface::class);
        $aggregation->expects(self::exactly(2))->method('aggregateHourly');

        $dailyStatsRepo = $this->createStub(DailyStatsRepositoryInterface::class);
        $dailyStatsRepo->method('findByDateRange')->willReturn([]);

        $job = new AggregationJob($aggregation, $siteRepo, $dailyStatsRepo);
        $job();
    }

    #[Test]
    public function empty_sites_no_aggregation(): void
    {
        $siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $siteRepo->method('findAll')->willReturn([]);

        /** @var AggregationServiceInterface&MockObject $aggregation */
        $aggregation = $this->createMock(AggregationServiceInterface::class);
        $aggregation->expects(self::never())->method('aggregateHourly');
        $aggregation->expects(self::never())->method('aggregateDaily');

        $dailyStatsRepo = $this->createStub(DailyStatsRepositoryInterface::class);

        $job = new AggregationJob($aggregation, $siteRepo, $dailyStatsRepo);
        $job();
    }
}
