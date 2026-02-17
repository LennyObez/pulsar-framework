<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor\PreflightCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;

#[CoversClass(PreflightCheckResult::class)]
final class PreflightCheckResultTest extends TestCase
{
    #[Test]
    public function passedResultWithDefaults(): void
    {
        $result = new PreflightCheckResult(passed: true, message: 'Disk ok');

        self::assertTrue($result->passed);
        self::assertSame('Disk ok', $result->message);
        self::assertSame([], $result->findings);
    }

    #[Test]
    public function failedResultWithFindings(): void
    {
        $result = new PreflightCheckResult(
            passed: false,
            message: 'DNS resolution failed',
            findings: ['api.example.com: timeout', 'db.example.com: NXDOMAIN'],
        );

        self::assertFalse($result->passed);
        self::assertCount(2, $result->findings);
        self::assertStringContainsString('timeout', $result->findings[0]);
    }
}
