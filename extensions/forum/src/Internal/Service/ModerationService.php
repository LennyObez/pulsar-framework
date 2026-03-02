<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Event\ReportResolved;
use Pulsar\Extension\Forum\Event\ReportSubmitted;
use Pulsar\Extension\Forum\Event\UserBanned;
use Pulsar\Extension\Forum\Event\UserUnbanned;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;

/**
 * Moderation service; reporting, review, and user ban management.
 */
#[Internal(reason: 'Use ModerationServiceInterface for public API')]
final readonly class ModerationService implements ModerationServiceInterface
{
    public function __construct(
        private ThreadReportRepositoryInterface $threadReports,
        private PostReportRepositoryInterface $postReports,
        private ForumProfileRepositoryInterface $profiles,
        private BadgeServiceInterface $badgeService,
        private EventDispatcherInterface $events,
    ) {}

    public function submitThreadReport(
        string $threadId,
        string $reporterId,
        string $reason,
        ?string $tenantId = null,
    ): ThreadReport {
        $existing = $this->threadReports->findByReporterAndThread($reporterId, $threadId);

        if ($existing !== null) {
            throw ForumException::duplicateReport($reporterId, $threadId);
        }

        $report = ThreadReport::create(
            id: UuidGenerator::v7(),
            threadId: $threadId,
            reporterId: $reporterId,
            reason: $reason,
            tenantId: $tenantId,
        );

        $this->threadReports->save($report);

        $this->events->dispatch(new ReportSubmitted(
            reportId: $report->id,
            targetType: 'thread',
            targetId: $threadId,
            reporterId: $reporterId,
            reason: $reason,
            tenantId: $tenantId,
        ));

        return $report;
    }

    public function submitPostReport(
        string $postId,
        string $reporterId,
        string $reason,
        ?string $tenantId = null,
    ): PostReport {
        $existing = $this->postReports->findByReporterAndPost($reporterId, $postId);

        if ($existing !== null) {
            throw ForumException::duplicateReport($reporterId, $postId);
        }

        $report = PostReport::create(
            id: UuidGenerator::v7(),
            postId: $postId,
            reporterId: $reporterId,
            reason: $reason,
            tenantId: $tenantId,
        );

        $this->postReports->save($report);

        $this->events->dispatch(new ReportSubmitted(
            reportId: $report->id,
            targetType: 'post',
            targetId: $postId,
            reporterId: $reporterId,
            reason: $reason,
            tenantId: $tenantId,
        ));

        return $report;
    }

    public function reviewThreadReport(
        string $reportId,
        ReportStatus $status,
        string $moderatorId,
        string $note = '',
    ): ThreadReport {
        $report = $this->threadReports->findById($reportId);

        if ($report === null) {
            throw ForumException::notFound('ThreadReport', $reportId);
        }

        $report = $report->review($status, $moderatorId, $note);
        $this->threadReports->save($report);

        $this->events->dispatch(new ReportResolved(
            reportId: $report->id,
            targetType: 'thread',
            targetId: $report->threadId,
            moderatorId: $moderatorId,
            resolution: $status,
            tenantId: $report->tenantId,
        ));

        // Evaluate BugHunter badge for the reporter if the report was actioned
        if ($status === ReportStatus::Actioned) {
            $this->badgeService->award($report->reporterId, Badge::BugHunter, $report->tenantId);
        }

        return $report;
    }

    public function reviewPostReport(
        string $reportId,
        ReportStatus $status,
        string $moderatorId,
        string $note = '',
    ): PostReport {
        $report = $this->postReports->findById($reportId);

        if ($report === null) {
            throw ForumException::notFound('PostReport', $reportId);
        }

        $report = $report->review($status, $moderatorId, $note);
        $this->postReports->save($report);

        $this->events->dispatch(new ReportResolved(
            reportId: $report->id,
            targetType: 'post',
            targetId: $report->postId,
            moderatorId: $moderatorId,
            resolution: $status,
            tenantId: $report->tenantId,
        ));

        // Evaluate BugHunter badge for the reporter if the report was actioned
        if ($status === ReportStatus::Actioned) {
            $this->badgeService->award($report->reporterId, Badge::BugHunter, $report->tenantId);
        }

        return $report;
    }

    public function startThreadReportReview(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): ThreadReport {
        return $this->reviewThreadReport($reportId, ReportStatus::UnderReview, $moderatorId, $note);
    }

    public function startPostReportReview(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): PostReport {
        return $this->reviewPostReport($reportId, ReportStatus::UnderReview, $moderatorId, $note);
    }

    public function resolveThreadReport(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): ThreadReport {
        return $this->reviewThreadReport($reportId, ReportStatus::Actioned, $moderatorId, $note);
    }

    public function resolvePostReport(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): PostReport {
        return $this->reviewPostReport($reportId, ReportStatus::Actioned, $moderatorId, $note);
    }

    public function dismissThreadReport(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): ThreadReport {
        return $this->reviewThreadReport($reportId, ReportStatus::Dismissed, $moderatorId, $note);
    }

    public function dismissPostReport(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): PostReport {
        return $this->reviewPostReport($reportId, ReportStatus::Dismissed, $moderatorId, $note);
    }

    public function banUser(
        string $userId,
        string $reason,
        ?DateTimeImmutable $expiresAt = null,
        string $moderatorId = '',
    ): ForumProfile {
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            throw ForumException::notFound('ForumProfile', $userId);
        }

        $profile = $profile->ban($reason, $expiresAt);
        $this->profiles->save($profile);

        $this->events->dispatch(new UserBanned(
            userId: $profile->userId,
            bannedBy: $moderatorId !== '' ? $moderatorId : $userId,
            reason: $reason,
            expiresAt: $expiresAt,
            tenantId: $profile->tenantId,
        ));

        return $profile;
    }

    public function unbanUser(string $userId, string $moderatorId = ''): ForumProfile
    {
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            throw ForumException::notFound('ForumProfile', $userId);
        }

        $profile = $profile->unban();
        $this->profiles->save($profile);

        $this->events->dispatch(new UserUnbanned(
            userId: $profile->userId,
            unbannedBy: $moderatorId !== '' ? $moderatorId : $userId,
            tenantId: $profile->tenantId,
        ));

        return $profile;
    }
}
