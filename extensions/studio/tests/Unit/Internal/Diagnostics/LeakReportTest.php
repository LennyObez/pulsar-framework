<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Internal\Diagnostics\LeakReport;

final class LeakReportTest extends TestCase
{
    #[Test]
    public function toArrayExportsAllFields(): void
    {
        $report = new LeakReport(
            suspected: true,
            growthPerRequestBytes: 51200,
            totalGrowthBytes: 5120000,
            sampleCount: 100,
            firstUsageBytes: 1048576,
            lastUsageBytes: 6168576,
            peakBytes: 7000000,
        );

        $array = $report->toArray();

        self::assertTrue($array['suspected']);
        self::assertSame(51200, $array['growth_per_request_bytes']);
        self::assertSame(5120000, $array['total_growth_bytes']);
        self::assertSame(100, $array['sample_count']);
        self::assertSame(1048576, $array['first_usage_bytes']);
        self::assertSame(6168576, $array['last_usage_bytes']);
        self::assertSame(7000000, $array['peak_bytes']);
    }

    #[Test]
    public function propertiesAreReadonly(): void
    {
        $report = new LeakReport(
            suspected: false,
            growthPerRequestBytes: 0,
            totalGrowthBytes: 0,
            sampleCount: 50,
            firstUsageBytes: 2048,
            lastUsageBytes: 2048,
            peakBytes: 4096,
        );

        self::assertFalse($report->suspected);
        self::assertSame(0, $report->growthPerRequestBytes);
        self::assertSame(50, $report->sampleCount);
    }
}
