<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Report\PostReport;

final class PostReportTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $report = PostReport::create(
            id: 'report-1',
            postId: 'post-1',
            reporterId: 'user-1',
            reason: 'Spam',
        );

        self::assertSame('report-1', $report->id);
        self::assertNull($report->tenantId);
        self::assertSame('post-1', $report->postId);
        self::assertSame('user-1', $report->reporterId);
        self::assertSame('Spam', $report->reason);
        self::assertSame(ReportStatus::Pending, $report->status);
        self::assertNull($report->moderatorId);
        self::assertNull($report->moderatorNote);
        self::assertEqualsWithDelta(time(), $report->createdAt->getTimestamp(), 2);
        self::assertNull($report->reviewedAt);
    }

    #[Test]
    public function createWithTenantId(): void
    {
        $report = PostReport::create(
            id: 'report-1',
            postId: 'post-1',
            reporterId: 'user-1',
            reason: 'Spam',
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $report->tenantId);
    }

    #[Test]
    public function reviewTransitionsToUnderReview(): void
    {
        $report = PostReport::create('r-1', 'p-1', 'u-1', 'Spam');
        $reviewed = $report->review(ReportStatus::UnderReview, 'mod-1', 'Investigating');

        self::assertSame(ReportStatus::UnderReview, $reviewed->status);
        self::assertSame('mod-1', $reviewed->moderatorId);
        self::assertSame('Investigating', $reviewed->moderatorNote);
        self::assertNotNull($reviewed->reviewedAt);
    }

    #[Test]
    public function reviewTransitionsToDismissed(): void
    {
        $report = PostReport::create('r-1', 'p-1', 'u-1', 'Spam');
        $dismissed = $report->review(ReportStatus::Dismissed, 'mod-1', 'Not spam');

        self::assertSame(ReportStatus::Dismissed, $dismissed->status);
    }

    #[Test]
    public function reviewThrowsOnInvalidTransition(): void
    {
        $report = PostReport::create('r-1', 'p-1', 'u-1', 'Spam');

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Invalid status transition');
        $report->review(ReportStatus::Actioned, 'mod-1');
    }

    #[Test]
    public function reviewThrowsFromTerminalState(): void
    {
        $report = PostReport::create('r-1', 'p-1', 'u-1', 'Spam');
        $dismissed = $report->review(ReportStatus::Dismissed, 'mod-1');

        $this->expectException(ForumException::class);
        $dismissed->review(ReportStatus::UnderReview, 'mod-2');
    }
}
