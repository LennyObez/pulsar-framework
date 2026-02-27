<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\HealthCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\HealthCheck\DiskHealthCheck;
use Pulsar\Resilience\HealthCheck\HealthStatus;

#[CoversClass(DiskHealthCheck::class)]
final class DiskHealthCheckTest extends TestCase
{
    #[Test]
    public function nameReturnsDisk(): void
    {
        $check = new DiskHealthCheck();

        self::assertSame('disk', $check->getName());
    }

    #[Test]
    public function checkReturnsHealthyWhenDiskSpaceSufficient(): void
    {
        // Use a real path that should exist and have ample free space
        $check = new DiskHealthCheck(path: sys_get_temp_dir(), thresholdMb: 1);
        $result = $check->check();

        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertSame('disk', $result->name);
        self::assertStringContainsString('MB free', $result->message);
        self::assertGreaterThanOrEqual(0.0, $result->responseTimeMs);
    }

    #[Test]
    public function checkReturnsUnhealthyWhenDiskSpaceLow(): void
    {
        // Set an impossibly high threshold that no disk could meet
        $check = new DiskHealthCheck(path: sys_get_temp_dir(), thresholdMb: 999_999_999);
        $result = $check->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertStringContainsString('Disk space low', $result->message);
    }

    #[Test]
    public function checkReturnsUnhealthyForInvalidPath(): void
    {
        $check = new DiskHealthCheck(path: '/nonexistent/path/that/cannot/exist');

        // Suppress disk_free_space() warning on nonexistent paths
        $result = @$check->check();

        // Either unhealthy from disk_free_space returning false or throwing
        self::assertSame(HealthStatus::Unhealthy, $result->status);
    }

    #[Test]
    public function checkRecordsResponseTimeMs(): void
    {
        $check = new DiskHealthCheck(path: sys_get_temp_dir());
        $result = $check->check();

        self::assertGreaterThanOrEqual(0.0, $result->responseTimeMs);
    }
}
