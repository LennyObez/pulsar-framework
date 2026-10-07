<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\ThreadReport;

/**
 * Moderation service: content reporting, report review, and user banning.
 * @api
 */
#[Api(since: '1.0.0')]
interface ModerationServiceInterface
{
    public function submitThreadReport(
        string $threadId,
        string $reporterId,
        string $reason,
        ?string $tenantId = null,
    ): ThreadReport;

    public function submitPostReport(
        string $postId,
        string $reporterId,
        string $reason,
        ?string $tenantId = null,
    ): PostReport;

    public function reviewThreadReport(
        string $reportId,
        ReportStatus $status,
        string $moderatorId,
        string $note = '',
    ): ThreadReport;

    public function reviewPostReport(
        string $reportId,
        ReportStatus $status,
        string $moderatorId,
        string $note = '',
    ): PostReport;

    /**
     * Transition a thread report from Pending to UnderReview.
     */
    public function startThreadReportReview(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): ThreadReport;

    /**
     * Transition a post report from Pending to UnderReview.
     */
    public function startPostReportReview(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): PostReport;

    /**
     * Resolve a thread report (Actioned): typically after moderator action.
     */
    public function resolveThreadReport(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): ThreadReport;

    /**
     * Resolve a post report (Actioned): typically after moderator action.
     */
    public function resolvePostReport(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): PostReport;

    /**
     * Dismiss a thread report: no action taken.
     */
    public function dismissThreadReport(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): ThreadReport;

    /**
     * Dismiss a post report: no action taken.
     */
    public function dismissPostReport(
        string $reportId,
        string $moderatorId,
        string $note = '',
    ): PostReport;

    public function banUser(
        string $userId,
        string $reason,
        ?DateTimeImmutable $expiresAt = null,
        string $moderatorId = '',
    ): ForumProfile;

    public function unbanUser(string $userId, string $moderatorId = ''): ForumProfile;
}
