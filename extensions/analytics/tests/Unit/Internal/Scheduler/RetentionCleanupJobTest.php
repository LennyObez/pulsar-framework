<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\RetentionConfig;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Internal\Repository\DbHourlyStatsRepository;
use Pulsar\Extension\Analytics\Internal\Scheduler\RetentionCleanupJob;

final class RetentionCleanupJobTest extends TestCase
{
    #[Test]
    public function invokeCallsDeleteOnAllRepositories(): void
    {
        $config = new AnalyticsConfig(
            retention: new RetentionConfig(rawDays: 30, aggregatedDays: 365, hourlyHours: 24),
        );

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())->method('deleteOlderThan')->willReturn(10);

        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $eventRepo->expects(self::once())->method('deleteOlderThan')->willReturn(5);

        $sessionRepo = $this->createMock(SessionRepositoryInterface::class);
        $sessionRepo->expects(self::once())->method('deleteOlderThan')->willReturn(3);

        $dailyStatsRepo = $this->createMock(DailyStatsRepositoryInterface::class);
        $dailyStatsRepo->expects(self::once())->method('deleteOlderThan')->willReturn(0);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(0);
        $hourlyStatsRepo = new DbHourlyStatsRepository($connection);

        $job = new RetentionCleanupJob(
            $config,
            $pageViewRepo,
            $eventRepo,
            $sessionRepo,
            $dailyStatsRepo,
            $hourlyStatsRepo,
        );

        ($job)();
    }
}
