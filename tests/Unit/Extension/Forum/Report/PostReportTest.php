<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Report\PostReport;

#[CoversClass(PostReport::class)]
final class PostReportTest extends TestCase
{
    #[Test]
    public function createFactoryProducesPendingReport(): void
    {
        $report = PostReport::create(id: 'report-001', postId: 'post-001', reporterId: 'user-001', reason: 'Spam content', tenantId: 'tenant-001');
        self::assertSame('report-001', $report->id);
        self::assertSame('tenant-001', $report->tenantId);
        self::assertSame('post-001', $report->postId);
        self::assertSame('user-001', $report->reporterId);
        self::assertSame('Spam content', $report->reason);
        self::assertSame(ReportStatus::Pending, $report->status);
        self::assertNull($report->moderatorId);
        self::assertNull($report->moderatorNote);
        self::assertNull($report->reviewedAt);
    }

    #[Test]
    public function createWithoutTenant(): void
    {
        $report = PostReport::create(id: 'r-001', postId: 'p-001', reporterId: 'u-001', reason: 'Spam');
        self::assertNull($report->tenantId);
    }

    #[Test]
    public function reviewTransitionsPendingToUnderReview(): void
    {
        $report = PostReport::create(id: 'r-001', postId: 'p-001', reporterId: 'u-001', reason: 'Spam');
        $reviewed = $report->review(ReportStatus::UnderReview, 'mod-001', 'Looking into it');
        self::assertSame(ReportStatus::UnderReview, $reviewed->status);
        self::assertSame('mod-001', $reviewed->moderatorId);
        self::assertSame('Looking into it', $reviewed->moderatorNote);
        self::assertNotNull($reviewed->reviewedAt);
    }

    #[Test]
    public function reviewTransitionsPendingToDismissed(): void
    {
        $report = PostReport::create(id: 'r-001', postId: 'p-001', reporterId: 'u-001', reason: 'Not real');
        $dismissed = $report->review(ReportStatus::Dismissed, 'mod-001', 'Not actionable');
        self::assertSame(ReportStatus::Dismissed, $dismissed->status);
    }

    #[Test]
    public function reviewTransitionsUnderReviewToActioned(): void
    {
        $report = PostReport::create(id: 'r-001', postId: 'p-001', reporterId: 'u-001', reason: 'Spam');
        $actioned = $report->review(ReportStatus::UnderReview, 'mod-001')->review(ReportStatus::Actioned, 'mod-001', 'Post removed');
        self::assertSame(ReportStatus::Actioned, $actioned->status);
    }

    #[Test]
    public function reviewThrowsOnInvalidTransition(): void
    {
        $report = PostReport::create(id: 'r-001', postId: 'p-001', reporterId: 'u-001', reason: 'Spam');
        $this->expectException(ForumException::class);
        $this->expectExceptionMessage("Invalid status transition from 'pending' to 'actioned'");
        $report->review(ReportStatus::Actioned, 'mod-001');
    }

    #[Test]
    public function reviewThrowsOnTerminalStateTransition(): void
    {
        $report = PostReport::create(id: 'r-001', postId: 'p-001', reporterId: 'u-001', reason: 'Spam');
        $dismissed = $report->review(ReportStatus::Dismissed, 'mod-001');
        $this->expectException(ForumException::class);
        $dismissed->review(ReportStatus::UnderReview, 'mod-002');
    }

    #[Test]
    public function reviewWithDefaultEmptyNote(): void
    {
        $report = PostReport::create(id: 'r-001', postId: 'p-001', reporterId: 'u-001', reason: 'Spam');
        $reviewed = $report->review(ReportStatus::UnderReview, 'mod-001');
        self::assertSame('', $reviewed->moderatorNote);
    }
}
