<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Report\ThreadReport;

#[CoversClass(ThreadReport::class)]
final class ThreadReportTest extends TestCase
{
    #[Test]
    public function createFactoryProducesPendingReport(): void
    {
        $report = ThreadReport::create(id: 'r-001', threadId: 'thread-001', reporterId: 'user-001', reason: 'Off-topic', tenantId: 'tenant-001');
        self::assertSame('r-001', $report->id);
        self::assertSame('tenant-001', $report->tenantId);
        self::assertSame('thread-001', $report->threadId);
        self::assertSame(ReportStatus::Pending, $report->status);
        self::assertNull($report->moderatorId);
        self::assertNull($report->reviewedAt);
    }

    #[Test]
    public function createWithoutTenant(): void
    {
        $report = ThreadReport::create(id: 'r-001', threadId: 't-001', reporterId: 'u-001', reason: 'Spam');
        self::assertNull($report->tenantId);
    }

    #[Test]
    public function reviewTransitionsPendingToUnderReview(): void
    {
        $report = ThreadReport::create(id: 'r-001', threadId: 't-001', reporterId: 'u-001', reason: 'Spam');
        $reviewed = $report->review(ReportStatus::UnderReview, 'mod-001', 'Investigating');
        self::assertSame(ReportStatus::UnderReview, $reviewed->status);
        self::assertSame('mod-001', $reviewed->moderatorId);
        self::assertNotNull($reviewed->reviewedAt);
    }

    #[Test]
    public function reviewTransitionsUnderReviewToActioned(): void
    {
        $report = ThreadReport::create(id: 'r-001', threadId: 't-001', reporterId: 'u-001', reason: 'Spam');
        $actioned = $report->review(ReportStatus::UnderReview, 'mod-001')->review(ReportStatus::Actioned, 'mod-001', 'Thread removed');
        self::assertSame(ReportStatus::Actioned, $actioned->status);
    }

    #[Test]
    public function reviewTransitionsUnderReviewToDismissed(): void
    {
        $report = ThreadReport::create(id: 'r-001', threadId: 't-001', reporterId: 'u-001', reason: 'Off-topic');
        $dismissed = $report->review(ReportStatus::UnderReview, 'mod-001')->review(ReportStatus::Dismissed, 'mod-001', 'On topic');
        self::assertSame(ReportStatus::Dismissed, $dismissed->status);
    }

    #[Test]
    public function reviewThrowsOnInvalidTransitionFromPending(): void
    {
        $report = ThreadReport::create(id: 'r-001', threadId: 't-001', reporterId: 'u-001', reason: 'Spam');
        $this->expectException(ForumException::class);
        $report->review(ReportStatus::Actioned, 'mod-001');
    }

    #[Test]
    public function reviewThrowsFromTerminalActioned(): void
    {
        $report = ThreadReport::create(id: 'r-001', threadId: 't-001', reporterId: 'u-001', reason: 'Spam');
        $actioned = $report->review(ReportStatus::UnderReview, 'mod-001')->review(ReportStatus::Actioned, 'mod-001');
        $this->expectException(ForumException::class);
        $actioned->review(ReportStatus::Dismissed, 'mod-002');
    }

    #[Test]
    public function reviewThrowsFromTerminalDismissed(): void
    {
        $report = ThreadReport::create(id: 'r-001', threadId: 't-001', reporterId: 'u-001', reason: 'Spam');
        $dismissed = $report->review(ReportStatus::Dismissed, 'mod-001');
        $this->expectException(ForumException::class);
        $dismissed->review(ReportStatus::UnderReview, 'mod-002');
    }
}
