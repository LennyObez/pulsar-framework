<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarDeadlineReport;
use Pulsar\DataProtection\Dsar\DsarRequest;
use Pulsar\DataProtection\Dsar\DsarStatus;

#[CoversClass(DsarDeadlineReport::class)]
final class DsarDeadlineReportTest extends TestCase
{
    #[Test]
    public function hasOverdueReturnsTrueWhenOverdueRequestsExist(): void
    {
        $overdueReq = $this->makeRequest('overdue-1', DsarStatus::Pending, '-40 days', '-10 days');

        $report = new DsarDeadlineReport(
            overdue: [$overdueReq],
            atRisk: [],
            onTrack: [],
        );

        self::assertTrue($report->hasOverdue());
    }

    #[Test]
    public function hasOverdueReturnsFalseWhenNoOverdueRequests(): void
    {
        $report = new DsarDeadlineReport(overdue: [], atRisk: [], onTrack: []);

        self::assertFalse($report->hasOverdue());
    }

    #[Test]
    public function isCompliantReturnsTrueWhenNoOverdueRequests(): void
    {
        $atRisk = $this->makeRequest('risk-1', DsarStatus::Processing, '-27 days', '+3 days');
        $onTrack = $this->makeRequest('track-1', DsarStatus::Pending, '-5 days', '+25 days');

        $report = new DsarDeadlineReport(
            overdue: [],
            atRisk: [$atRisk],
            onTrack: [$onTrack],
        );

        self::assertTrue($report->isCompliant());
    }

    #[Test]
    public function isCompliantReturnsFalseWhenOverdueExists(): void
    {
        $overdue = $this->makeRequest('overdue-1', DsarStatus::Processing, '-40 days', '-10 days');

        $report = new DsarDeadlineReport(
            overdue: [$overdue],
            atRisk: [],
            onTrack: [],
        );

        self::assertFalse($report->isCompliant());
    }

    #[Test]
    public function emptyReportIsCompliant(): void
    {
        $report = new DsarDeadlineReport(overdue: [], atRisk: [], onTrack: []);

        self::assertTrue($report->isCompliant());
        self::assertFalse($report->hasOverdue());
    }

    #[Test]
    public function allCategoriesCanHaveMultipleRequests(): void
    {
        $overdue1 = $this->makeRequest('o1', DsarStatus::Pending, '-50 days', '-20 days');
        $overdue2 = $this->makeRequest('o2', DsarStatus::Processing, '-45 days', '-15 days');
        $atRisk1 = $this->makeRequest('r1', DsarStatus::Processing, '-27 days', '+3 days');
        $onTrack1 = $this->makeRequest('t1', DsarStatus::Pending, '-2 days', '+28 days');
        $onTrack2 = $this->makeRequest('t2', DsarStatus::Pending, '-1 day', '+29 days');

        $report = new DsarDeadlineReport(
            overdue: [$overdue1, $overdue2],
            atRisk: [$atRisk1],
            onTrack: [$onTrack1, $onTrack2],
        );

        self::assertCount(2, $report->overdue);
        self::assertCount(1, $report->atRisk);
        self::assertCount(2, $report->onTrack);
        self::assertFalse($report->isCompliant());
    }

    private function makeRequest(
        string $id,
        DsarStatus $status,
        string $createdOffset,
        string $deadlineOffset,
    ): DsarRequest {
        return new DsarRequest(
            id: $id,
            subjectId: 'sub-' . $id,
            email: $id . '@example.com',
            status: $status,
            createdAt: new DateTimeImmutable($createdOffset),
            deadline: new DateTimeImmutable($deadlineOffset),
        );
    }
}
