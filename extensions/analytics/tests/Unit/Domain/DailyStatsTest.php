<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\DailyStats;

final class DailyStatsTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $date = new DateTimeImmutable('2026-03-01');

        $stats = new DailyStats(
            siteId: 'site-1',
            date: $date,
            visitors: 150,
            pageviews: 420,
            sessions: 180,
            bounceRate: 35.5,
            avgDuration: 120.3,
            eventsCount: 45,
        );

        self::assertSame('site-1', $stats->siteId);
        self::assertSame($date, $stats->date);
        self::assertSame(150, $stats->visitors);
        self::assertSame(420, $stats->pageviews);
        self::assertSame(180, $stats->sessions);
        self::assertSame(35.5, $stats->bounceRate);
        self::assertSame(120.3, $stats->avgDuration);
        self::assertSame(45, $stats->eventsCount);
    }

    #[Test]
    public function constructWithDefaults(): void
    {
        $date = new DateTimeImmutable('2026-03-01');

        $stats = new DailyStats(siteId: 'site-1', date: $date);

        self::assertSame(0, $stats->visitors);
        self::assertSame(0, $stats->pageviews);
        self::assertSame(0, $stats->sessions);
        self::assertSame(0.0, $stats->bounceRate);
        self::assertSame(0.0, $stats->avgDuration);
        self::assertSame(0, $stats->eventsCount);
    }
}
