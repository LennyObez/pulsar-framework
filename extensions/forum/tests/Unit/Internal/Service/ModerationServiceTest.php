<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ModerationService;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;

final class ModerationServiceTest extends TestCase
{
    private ThreadReportRepositoryInterface&Stub $threadReports;
    private PostReportRepositoryInterface&Stub $postReports;
    private ForumProfileRepositoryInterface&Stub $profiles;
    private BadgeServiceInterface&Stub $badgeService;
    private EventDispatcherInterface&Stub $events;
    private ModerationService $service;

    protected function setUp(): void
    {
        $this->threadReports = $this->createStub(ThreadReportRepositoryInterface::class);
        $this->postReports = $this->createStub(PostReportRepositoryInterface::class);
        $this->profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $this->badgeService = $this->createStub(BadgeServiceInterface::class);
        $this->events = $this->createStub(EventDispatcherInterface::class);

        $this->service = new ModerationService(
            $this->threadReports,
            $this->postReports,
            $this->profiles,
            $this->badgeService,
            $this->events,
        );
    }

    #[Test]
    public function submit_thread_report_creates_and_dispatches(): void
    {
        $this->threadReports->method('findByReporterAndThread')->willReturn(null);

        $report = $this->service->submitThreadReport('thread-1', 'user-1', 'spam');

        self::assertInstanceOf(ThreadReport::class, $report);
        self::assertSame('thread-1', $report->threadId);
        self::assertSame('user-1', $report->reporterId);
        self::assertSame('spam', $report->reason);
    }

    #[Test]
    public function submit_thread_report_prevents_duplicates(): void
    {
        $existing = ThreadReport::create('r1', 'thread-1', 'user-1', 'spam');
        $this->threadReports->method('findByReporterAndThread')->willReturn($existing);

        $this->expectException(ForumException::class);

        $this->service->submitThreadReport('thread-1', 'user-1', 'also spam');
    }

    #[Test]
    public function submit_post_report_creates_and_dispatches(): void
    {
        $this->postReports->method('findByReporterAndPost')->willReturn(null);

        $report = $this->service->submitPostReport('post-1', 'user-1', 'harassment');

        self::assertInstanceOf(PostReport::class, $report);
        self::assertSame('post-1', $report->postId);
    }

    #[Test]
    public function submit_post_report_prevents_duplicates(): void
    {
        $existing = PostReport::create('r1', 'post-1', 'user-1', 'spam');
        $this->postReports->method('findByReporterAndPost')->willReturn($existing);

        $this->expectException(ForumException::class);

        $this->service->submitPostReport('post-1', 'user-1', 'also spam');
    }

    #[Test]
    public function review_thread_report_not_found_throws(): void
    {
        $this->threadReports->method('findById')->willReturn(null);

        $this->expectException(ForumException::class);

        $this->service->reviewThreadReport('missing', ReportStatus::Actioned, 'mod-1');
    }

    #[Test]
    public function ban_user_updates_profile(): void
    {
        $profile = ForumProfile::create(
            id: 'prof-1',
            userId: 'user-1',
        );

        $this->profiles->method('findByUser')->willReturn($profile);

        $result = $this->service->banUser('user-1', 'spam behavior', moderatorId: 'mod-1');

        self::assertTrue($result->isBanned);
    }

    #[Test]
    public function ban_user_throws_for_unknown_user(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $this->expectException(ForumException::class);

        $this->service->banUser('unknown', 'test');
    }

    #[Test]
    public function unban_user_removes_ban(): void
    {
        $profile = ForumProfile::create(
            id: 'prof-1',
            userId: 'user-1',
        );
        $bannedProfile = $profile->ban('spam');

        $this->profiles->method('findByUser')->willReturn($bannedProfile);

        $result = $this->service->unbanUser('user-1', 'mod-1');

        self::assertFalse($result->isBanned);
    }

    #[Test]
    public function unban_user_throws_for_unknown_user(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $this->expectException(ForumException::class);

        $this->service->unbanUser('unknown');
    }
}
