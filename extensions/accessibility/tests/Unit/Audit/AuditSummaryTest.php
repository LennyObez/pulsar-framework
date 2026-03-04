<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Audit\AuditSummary;

final class AuditSummaryTest extends TestCase
{
    #[Test]
    public function constructorComputesTotalViolations(): void
    {
        $summary = new AuditSummary(
            totalChecks: 100,
            errorCount: 5,
            warningCount: 10,
            infoCount: 3,
            filesAudited: 8,
        );

        self::assertSame(100, $summary->totalChecks);
        self::assertSame(5, $summary->errorCount);
        self::assertSame(10, $summary->warningCount);
        self::assertSame(3, $summary->infoCount);
        self::assertSame(8, $summary->filesAudited);
        self::assertSame(18, $summary->totalViolations);
    }

    #[Test]
    public function totalViolationsIsZeroWhenAllCountsAreZero(): void
    {
        $summary = new AuditSummary(50, 0, 0, 0, 5);

        self::assertSame(0, $summary->totalViolations);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $summary = new AuditSummary(
            totalChecks: 200,
            errorCount: 2,
            warningCount: 7,
            infoCount: 1,
            filesAudited: 15,
        );

        $array = $summary->toArray();

        self::assertSame(200, $array['total_checks']);
        self::assertSame(2, $array['errors']);
        self::assertSame(7, $array['warnings']);
        self::assertSame(1, $array['info']);
        self::assertSame(10, $array['total_violations']);
        self::assertSame(15, $array['files_audited']);
    }

    #[Test]
    public function toArrayContainsSixKeys(): void
    {
        $summary = new AuditSummary(0, 0, 0, 0, 0);

        self::assertCount(6, $summary->toArray());
    }
}
