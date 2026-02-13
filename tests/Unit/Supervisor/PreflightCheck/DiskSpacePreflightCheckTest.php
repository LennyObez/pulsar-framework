<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor\PreflightCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\PreflightCheck\DiskSpacePreflightCheck;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;

use function sys_get_temp_dir;

#[CoversClass(DiskSpacePreflightCheck::class)]
final class DiskSpacePreflightCheckTest extends TestCase
{
    #[Test]
    public function it_returns_disk_space_as_name(): void
    {
        $check = new DiskSpacePreflightCheck();

        self::assertSame('disk_space', $check->getName());
    }

    #[Test]
    public function it_passes_when_free_space_exceeds_minimum(): void
    {
        // Use temp dir and a very low minimum to ensure pass
        $check = new DiskSpacePreflightCheck(
            path: sys_get_temp_dir(),
            minimumFreeMb: 1,
        );

        $result = $check->check();

        self::assertTrue($result->passed);
        self::assertStringContainsString('meets minimum requirement', $result->message);
    }

    #[Test]
    public function it_fails_when_free_space_is_below_minimum(): void
    {
        // Use an impossibly high minimum to guarantee failure
        $check = new DiskSpacePreflightCheck(
            path: sys_get_temp_dir(),
            minimumFreeMb: 999_999_999,
        );

        $result = $check->check();

        self::assertFalse($result->passed);
        self::assertStringContainsString('is below minimum', $result->message);
    }

    #[Test]
    public function it_includes_path_and_space_in_findings(): void
    {
        $tempDir = sys_get_temp_dir();
        $check = new DiskSpacePreflightCheck(
            path: $tempDir,
            minimumFreeMb: 1,
        );

        $result = $check->check();

        self::assertCount(3, $result->findings);
        self::assertStringContainsString($tempDir, $result->findings[0]);
        self::assertMatchesRegularExpression('/Free disk space: \d+ MB/', $result->findings[1]);
        self::assertSame('Minimum required: 1 MB', $result->findings[2]);
    }

    #[Test]
    public function it_fails_for_nonexistent_path(): void
    {
        $check = new DiskSpacePreflightCheck(
            path: '/nonexistent/path/that/does/not/exist',
            minimumFreeMb: 1,
        );

        $result = $check->check();

        self::assertFalse($result->passed);
        self::assertStringContainsString('Unable to determine', $result->message);
        self::assertCount(1, $result->findings);
    }

    #[Test]
    public function it_uses_default_path_and_minimum(): void
    {
        $check = new DiskSpacePreflightCheck();

        self::assertSame('disk_space', $check->getName());

        // Default path is '/', default minimum is 100 MB
        // Just verify it doesn't throw
        $result = $check->check();
        self::assertInstanceOf(PreflightCheckResult::class, $result);
    }

    #[Test]
    public function it_reports_actual_free_space_in_message(): void
    {
        $tempDir = sys_get_temp_dir();

        $check = new DiskSpacePreflightCheck(
            path: $tempDir,
            minimumFreeMb: 1,
        );

        $result = $check->check();

        self::assertMatchesRegularExpression('/Free disk space: \d+ MB/', $result->findings[1]);
    }
}
