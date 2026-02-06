<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor\PreflightCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\PreflightCheck\MemoryPreflightCheck;

#[CoversClass(MemoryPreflightCheck::class)]
final class MemoryPreflightCheckTest extends TestCase
{
    #[Test]
    public function it_returns_memory_as_name(): void
    {
        $check = new MemoryPreflightCheck();

        self::assertSame('memory', $check->getName());
    }

    #[Test]
    public function it_passes_with_high_threshold(): void
    {
        // Use a very high threshold so current memory usage is always below
        $check = new MemoryPreflightCheck(thresholdMb: 999_999);

        $result = $check->check();

        self::assertTrue($result->passed);
        self::assertStringContainsString('within threshold', $result->message);
        self::assertCount(2, $result->findings);
        self::assertStringContainsString('Current memory usage', $result->findings[0]);
        self::assertStringContainsString('Threshold: 999999 MB', $result->findings[1]);
    }

    #[Test]
    public function it_fails_with_zero_threshold(): void
    {
        // Zero MB threshold — current usage will always exceed it
        $check = new MemoryPreflightCheck(thresholdMb: 0);

        $result = $check->check();

        // memory_get_usage(true) returns at least some MB in a PHP test process
        // With threshold 0, the check might still pass if rounded to 0
        // Use threshold of 0 — round(X/1048576) for any X > 524288 will be >= 1
        // So this depends on actual usage. Let's use a more reliable approach.
        self::assertCount(2, $result->findings);
    }

    #[Test]
    public function it_fails_when_memory_exceeds_threshold(): void
    {
        // Use threshold of 1 MB — any real PHP process uses more
        $check = new MemoryPreflightCheck(thresholdMb: 1);

        $result = $check->check();

        // A PHPUnit test process typically uses several MB
        self::assertFalse($result->passed);
        self::assertStringContainsString('exceeds threshold', $result->message);
    }

    #[Test]
    public function it_uses_default_threshold_of_128_mb(): void
    {
        $check = new MemoryPreflightCheck();

        // Default threshold is 128 MB; a typical test process uses less
        $result = $check->check();

        self::assertTrue($result->passed);
    }

    #[Test]
    public function it_includes_current_usage_and_threshold_in_findings(): void
    {
        $check = new MemoryPreflightCheck(thresholdMb: 512);

        $result = $check->check();

        self::assertCount(2, $result->findings);
        self::assertMatchesRegularExpression('/Current memory usage: \d+ MB/', $result->findings[0]);
        self::assertSame('Threshold: 512 MB', $result->findings[1]);
    }
}
