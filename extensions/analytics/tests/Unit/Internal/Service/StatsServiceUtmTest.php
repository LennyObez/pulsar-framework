<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;
use Pulsar\Extension\Analytics\Internal\Service\StatsService;

final class StatsServiceUtmTest extends TestCase
{
    #[Test]
    public function getBreakdownUsesUtmSourceTable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'analytics_daily_referrers')
                        && str_contains($sql, 'utm_source AS name');
                }),
                self::anything(),
            )
            ->willReturn(new Result([]));

        $repo = $this->createStub(DailyStatsRepositoryInterface::class);
        $service = new StatsService($repo, $connection);

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        $result = $service->getBreakdown('site_1', $from, $to, BreakdownDimension::UtmSource);
        self::assertSame([], $result);
    }

    #[Test]
    public function getBreakdownUsesUtmMediumTable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'analytics_daily_referrers')
                        && str_contains($sql, 'utm_medium AS name');
                }),
                self::anything(),
            )
            ->willReturn(new Result([]));

        $repo = $this->createStub(DailyStatsRepositoryInterface::class);
        $service = new StatsService($repo, $connection);

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        $result = $service->getBreakdown('site_1', $from, $to, BreakdownDimension::UtmMedium);
        self::assertSame([], $result);
    }

    #[Test]
    public function getBreakdownUsesUtmCampaignTable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'analytics_daily_referrers')
                        && str_contains($sql, 'utm_campaign AS name');
                }),
                self::anything(),
            )
            ->willReturn(new Result([]));

        $repo = $this->createStub(DailyStatsRepositoryInterface::class);
        $service = new StatsService($repo, $connection);

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        $result = $service->getBreakdown('site_1', $from, $to, BreakdownDimension::UtmCampaign);
        self::assertSame([], $result);
    }

    #[Test]
    public function getBreakdownPageUsesPageTable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'analytics_daily_pages')
                        && str_contains($sql, 'pathname AS name');
                }),
                self::anything(),
            )
            ->willReturn(new Result([]));

        $repo = $this->createStub(DailyStatsRepositoryInterface::class);
        $service = new StatsService($repo, $connection);

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        $result = $service->getBreakdown('site_1', $from, $to, BreakdownDimension::Page);
        self::assertSame([], $result);
    }
}
