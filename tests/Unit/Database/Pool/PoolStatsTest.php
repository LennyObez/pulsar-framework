<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Pool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Pool\PoolStats;

#[CoversClass(PoolStats::class)]
final class PoolStatsTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $stats = new PoolStats(
            activeCount: 3,
            idleCount: 7,
            totalCreated: 15,
            totalDestroyed: 5,
            waitCount: 2,
        );

        self::assertSame(3, $stats->activeCount);
        self::assertSame(7, $stats->idleCount);
        self::assertSame(15, $stats->totalCreated);
        self::assertSame(5, $stats->totalDestroyed);
        self::assertSame(2, $stats->waitCount);
    }

    #[Test]
    public function constructionWithZeroValues(): void
    {
        $stats = new PoolStats(
            activeCount: 0,
            idleCount: 0,
            totalCreated: 0,
            totalDestroyed: 0,
            waitCount: 0,
        );

        self::assertSame(0, $stats->activeCount);
        self::assertSame(0, $stats->idleCount);
        self::assertSame(0, $stats->totalCreated);
        self::assertSame(0, $stats->totalDestroyed);
        self::assertSame(0, $stats->waitCount);
    }
}
