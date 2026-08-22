<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Monitor\ProcessingTimeStats;

#[CoversClass(ProcessingTimeStats::class)]
final class ProcessingTimeStatsTest extends TestCase
{
    #[Test]
    public function holdsPercentileValues(): void
    {
        $stats = new ProcessingTimeStats(
            p50: 12.5,
            p95: 45.8,
            p99: 98.2,
            average: 18.3,
            sampleCount: 1500,
        );

        self::assertSame(12.5, $stats->p50);
        self::assertSame(45.8, $stats->p95);
        self::assertSame(98.2, $stats->p99);
        self::assertSame(18.3, $stats->average);
        self::assertSame(1500, $stats->sampleCount);
    }
}
