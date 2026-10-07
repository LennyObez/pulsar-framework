<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\HourlyStats;

final class HourlyStatsTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $date = new DateTimeImmutable('2026-03-01 14:00:00');

        $stats = new HourlyStats(
            siteId: 'site-1',
            date: $date,
            hour: 14,
            visitors: 25,
            pageviews: 60,
            sessions: 30,
            bounceRate: 40.0,
            avgDuration: 90.5,
            eventsCount: 8,
        );

        self::assertSame('site-1', $stats->siteId);
        self::assertSame($date, $stats->date);
        self::assertSame(14, $stats->hour);
        self::assertSame(25, $stats->visitors);
        self::assertSame(60, $stats->pageviews);
        self::assertSame(30, $stats->sessions);
        self::assertSame(40.0, $stats->bounceRate);
        self::assertSame(90.5, $stats->avgDuration);
        self::assertSame(8, $stats->eventsCount);
    }

    #[Test]
    public function constructWithDefaults(): void
    {
        $date = new DateTimeImmutable('2026-03-01 14:00:00');

        $stats = new HourlyStats(siteId: 'site-1', date: $date, hour: 14);

        self::assertSame(0, $stats->visitors);
        self::assertSame(0, $stats->pageviews);
        self::assertSame(0, $stats->sessions);
        self::assertSame(0.0, $stats->bounceRate);
        self::assertSame(0.0, $stats->avgDuration);
        self::assertSame(0, $stats->eventsCount);
    }
}
