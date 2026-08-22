<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Audit\AuditSummary;

#[CoversClass(AuditSummary::class)]
final class AuditSummaryTest extends TestCase
{
    #[Test]
    public function calculatesTotalViolations(): void
    {
        $summary = new AuditSummary(
            totalChecks: 50,
            errorCount: 3,
            warningCount: 5,
            infoCount: 2,
            filesAudited: 10,
        );

        self::assertSame(10, $summary->totalViolations);
        self::assertSame(50, $summary->totalChecks);
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $summary = new AuditSummary(
            totalChecks: 100,
            errorCount: 0,
            warningCount: 1,
            infoCount: 0,
            filesAudited: 5,
        );

        $array = $summary->toArray();

        self::assertSame(100, $array['total_checks']);
        self::assertSame(0, $array['errors']);
        self::assertSame(1, $array['warnings']);
        self::assertSame(0, $array['info']);
        self::assertSame(1, $array['total_violations']);
        self::assertSame(5, $array['files_audited']);
    }

    #[Test]
    public function zeroViolationsWhenAllClear(): void
    {
        $summary = new AuditSummary(
            totalChecks: 200,
            errorCount: 0,
            warningCount: 0,
            infoCount: 0,
            filesAudited: 20,
        );

        self::assertSame(0, $summary->totalViolations);
    }
}
