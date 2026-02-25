<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Report\ThreadReport;

final class ThreadReportTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $report = ThreadReport::create(
            id: 'report-1',
            threadId: 'thread-1',
            reporterId: 'user-1',
            reason: 'Off-topic',
        );

        self::assertSame('report-1', $report->id);
        self::assertNull($report->tenantId);
        self::assertSame('thread-1', $report->threadId);
        self::assertSame('user-1', $report->reporterId);
        self::assertSame('Off-topic', $report->reason);
        self::assertSame(ReportStatus::Pending, $report->status);
        self::assertNull($report->moderatorId);
        self::assertNull($report->moderatorNote);
        self::assertNull($report->reviewedAt);
    }

    #[Test]
    public function createWithTenantId(): void
    {
        $report = ThreadReport::create(
            id: 'report-1',
            threadId: 'thread-1',
            reporterId: 'user-1',
            reason: 'Spam',
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $report->tenantId);
    }

    #[Test]
    public function reviewTransitionsToActioned(): void
    {
        $report = ThreadReport::create('r-1', 't-1', 'u-1', 'Spam');
        $underReview = $report->review(ReportStatus::UnderReview, 'mod-1');
        $actioned = $underReview->review(ReportStatus::Actioned, 'mod-1', 'Content removed');

        self::assertSame(ReportStatus::Actioned, $actioned->status);
        self::assertSame('Content removed', $actioned->moderatorNote);
    }

    #[Test]
    public function reviewThrowsOnInvalidTransition(): void
    {
        $report = ThreadReport::create('r-1', 't-1', 'u-1', 'Spam');

        $this->expectException(ForumException::class);
        $report->review(ReportStatus::Actioned, 'mod-1');
    }
}
