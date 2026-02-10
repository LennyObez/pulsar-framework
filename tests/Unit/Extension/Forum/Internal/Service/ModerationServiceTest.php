<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Event\ReportResolved;
use Pulsar\Extension\Forum\Event\ReportSubmitted;
use Pulsar\Extension\Forum\Event\UserBanned;
use Pulsar\Extension\Forum\Event\UserUnbanned;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ModerationService;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;

#[CoversClass(ModerationService::class)]
final class ModerationServiceTest extends TestCase
{
    private ThreadReportRepositoryInterface&Stub $threadReports;
    private PostReportRepositoryInterface&Stub $postReports;
    private ForumProfileRepositoryInterface&Stub $profiles;
    private BadgeServiceInterface&Stub $badgeService;
    private EventDispatcherInterface&Stub $events;

    protected function setUp(): void
    {
        $this->threadReports = $this->createStub(ThreadReportRepositoryInterface::class);
        $this->postReports = $this->createStub(PostReportRepositoryInterface::class);
        $this->profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $this->badgeService = $this->createStub(BadgeServiceInterface::class);
        $this->events = $this->createStub(EventDispatcherInterface::class);
    }

    private function makeService(
        ?ThreadReportRepositoryInterface $threadReports = null,
        ?PostReportRepositoryInterface $postReports = null,
        ?ForumProfileRepositoryInterface $profiles = null,
        ?EventDispatcherInterface $events = null,
    ): ModerationService {
        return new ModerationService(
            threadReports: $threadReports ?? $this->threadReports,
            postReports: $postReports ?? $this->postReports,
            profiles: $profiles ?? $this->profiles,
            badgeService: $this->badgeService,
            events: $events ?? $this->events,
        );
    }

    #[Test]
    public function submitThreadReportCreatesAndDispatchesEvent(): void
    {
        $threadReports = $this->createMock(ThreadReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threadReports->method('findByReporterAndThread')->willReturn(null);
        $threadReports->expects(self::once())->method('save')->with(self::isInstanceOf(ThreadReport::class));
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ReportSubmitted::class));

        $service = $this->makeService(threadReports: $threadReports, events: $events);

        $report = $service->submitThreadReport('thread-1', 'reporter-1', 'Spam content');

        self::assertSame('thread-1', $report->threadId);
        self::assertSame('reporter-1', $report->reporterId);
        self::assertSame('Spam content', $report->reason);
        self::assertSame(ReportStatus::Pending, $report->status);
    }

    #[Test]
    public function submitThreadReportThrowsOnDuplicate(): void
    {
        $existing = ThreadReport::create('r-1', 'thread-1', 'reporter-1', 'reason');
        $this->threadReports->method('findByReporterAndThread')->willReturn($existing);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('already has a pending report');

        $service->submitThreadReport('thread-1', 'reporter-1', 'Duplicate');
    }

    #[Test]
    public function submitPostReportCreatesAndDispatchesEvent(): void
    {
        $postReports = $this->createMock(PostReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $postReports->method('findByReporterAndPost')->willReturn(null);
        $postReports->expects(self::once())->method('save')->with(self::isInstanceOf(PostReport::class));
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ReportSubmitted::class));

        $service = $this->makeService(postReports: $postReports, events: $events);

        $report = $service->submitPostReport('post-1', 'reporter-1', 'Offensive');

        self::assertSame('post-1', $report->postId);
        self::assertSame(ReportStatus::Pending, $report->status);
    }

    #[Test]
    public function submitPostReportThrowsOnDuplicate(): void
    {
        $existing = PostReport::create('r-1', 'post-1', 'reporter-1', 'reason');
        $this->postReports->method('findByReporterAndPost')->willReturn($existing);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('already has a pending report');

        $service->submitPostReport('post-1', 'reporter-1', 'Duplicate');
    }

    #[Test]
    public function reviewThreadReportTransitionsToUnderReview(): void
    {
        $report = ThreadReport::create('r-1', 'thread-1', 'reporter-1', 'reason');

        $threadReports = $this->createMock(ThreadReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threadReports->method('findById')->willReturn($report);
        $threadReports->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ReportResolved::class));

        $service = $this->makeService(threadReports: $threadReports, events: $events);

        $reviewed = $service->reviewThreadReport('r-1', ReportStatus::UnderReview, 'mod-1', 'Checking');

        self::assertSame(ReportStatus::UnderReview, $reviewed->status);
        self::assertSame('mod-1', $reviewed->moderatorId);
    }

    #[Test]
    public function reviewThreadReportThrowsWhenNotFound(): void
    {
        $this->threadReports->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('ThreadReport not found');

        $service->reviewThreadReport('missing', ReportStatus::UnderReview, 'mod-1');
    }

    #[Test]
    public function reviewPostReportTransitionsToActioned(): void
    {
        $report = PostReport::create('r-1', 'post-1', 'reporter-1', 'Bad content');
        $underReview = $report->review(ReportStatus::UnderReview, 'mod-1');

        $postReports = $this->createMock(PostReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $postReports->method('findById')->willReturn($underReview);
        $postReports->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(postReports: $postReports, events: $events);

        $result = $service->reviewPostReport('r-1', ReportStatus::Actioned, 'mod-1');

        self::assertSame(ReportStatus::Actioned, $result->status);
    }

    #[Test]
    public function reviewPostReportThrowsWhenNotFound(): void
    {
        $this->postReports->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('PostReport not found');

        $service->reviewPostReport('missing', ReportStatus::UnderReview, 'mod-1');
    }

    #[Test]
    public function startThreadReportReviewDelegatesToReview(): void
    {
        $report = ThreadReport::create('r-1', 'thread-1', 'reporter-1', 'reason');

        $threadReports = $this->createMock(ThreadReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threadReports->method('findById')->willReturn($report);
        $threadReports->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(threadReports: $threadReports, events: $events);

        $result = $service->startThreadReportReview('r-1', 'mod-1', 'Starting review');

        self::assertSame(ReportStatus::UnderReview, $result->status);
    }

    #[Test]
    public function startPostReportReviewDelegatesToReview(): void
    {
        $report = PostReport::create('r-1', 'post-1', 'reporter-1', 'reason');

        $postReports = $this->createMock(PostReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $postReports->method('findById')->willReturn($report);
        $postReports->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(postReports: $postReports, events: $events);

        $result = $service->startPostReportReview('r-1', 'mod-1');

        self::assertSame(ReportStatus::UnderReview, $result->status);
    }

    #[Test]
    public function resolveThreadReportTransitionsToActioned(): void
    {
        $report = ThreadReport::create('r-1', 'thread-1', 'reporter-1', 'reason');
        $underReview = $report->review(ReportStatus::UnderReview, 'mod-1');

        $threadReports = $this->createMock(ThreadReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threadReports->method('findById')->willReturn($underReview);
        $threadReports->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(threadReports: $threadReports, events: $events);

        $result = $service->resolveThreadReport('r-1', 'mod-1', 'Resolved');

        self::assertSame(ReportStatus::Actioned, $result->status);
    }

    #[Test]
    public function resolvePostReportTransitionsToActioned(): void
    {
        $report = PostReport::create('r-1', 'post-1', 'reporter-1', 'reason');
        $underReview = $report->review(ReportStatus::UnderReview, 'mod-1');

        $postReports = $this->createMock(PostReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $postReports->method('findById')->willReturn($underReview);
        $postReports->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(postReports: $postReports, events: $events);

        $result = $service->resolvePostReport('r-1', 'mod-1');

        self::assertSame(ReportStatus::Actioned, $result->status);
    }

    #[Test]
    public function dismissThreadReportTransitionsToDismissed(): void
    {
        $report = ThreadReport::create('r-1', 'thread-1', 'reporter-1', 'reason');

        $threadReports = $this->createMock(ThreadReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threadReports->method('findById')->willReturn($report);
        $threadReports->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(threadReports: $threadReports, events: $events);

        $result = $service->dismissThreadReport('r-1', 'mod-1', 'Not valid');

        self::assertSame(ReportStatus::Dismissed, $result->status);
    }

    #[Test]
    public function dismissPostReportTransitionsToDismissed(): void
    {
        $report = PostReport::create('r-1', 'post-1', 'reporter-1', 'reason');

        $postReports = $this->createMock(PostReportRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $postReports->method('findById')->willReturn($report);
        $postReports->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(postReports: $postReports, events: $events);

        $result = $service->dismissPostReport('r-1', 'mod-1');

        self::assertSame(ReportStatus::Dismissed, $result->status);
    }

    #[Test]
    public function banUserSetsBanFieldsAndDispatchesEvent(): void
    {
        $profile = ForumProfile::create('p-1', 'user-1');

        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $profiles->method('findByUser')->willReturn($profile);
        $profiles->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(UserBanned::class));

        $service = $this->makeService(profiles: $profiles, events: $events);

        $expires = new DateTimeImmutable('+7 days');
        $result = $service->banUser('user-1', 'Spam', $expires, 'mod-1');

        self::assertTrue($result->isBanned);
        self::assertSame('Spam', $result->banReason);
        self::assertSame($expires, $result->banExpiresAt);
    }

    #[Test]
    public function banUserThrowsWhenProfileNotFound(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('ForumProfile not found');

        $service->banUser('unknown', 'reason');
    }

    #[Test]
    public function banUserPermanentWithNoExpiry(): void
    {
        $profile = ForumProfile::create('p-1', 'user-1');

        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $profiles->method('findByUser')->willReturn($profile);
        $profiles->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(profiles: $profiles, events: $events);

        $result = $service->banUser('user-1', 'Severe violation');

        self::assertTrue($result->isBanned);
        self::assertNull($result->banExpiresAt);
    }

    #[Test]
    public function unbanUserClearsBanAndDispatchesEvent(): void
    {
        $profile = ForumProfile::create('p-1', 'user-1');
        $banned = $profile->ban('Spam');

        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $profiles->method('findByUser')->willReturn($banned);
        $profiles->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(UserUnbanned::class));

        $service = $this->makeService(profiles: $profiles, events: $events);

        $result = $service->unbanUser('user-1', 'mod-1');

        self::assertFalse($result->isBanned);
        self::assertNull($result->banReason);
    }

    #[Test]
    public function unbanUserThrowsWhenProfileNotFound(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('ForumProfile not found');

        $service->unbanUser('unknown');
    }
}
