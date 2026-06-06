<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * API controller for moderation operations (requires moderator permissions).
 */
#[Internal(reason: 'Forum REST API controller; implementation detail')]
final readonly class ModerationApiController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private ThreadReportRepositoryInterface $threadReportRepository,
        private PostReportRepositoryInterface $postReportRepository,
        private ModerationServiceInterface $moderationService,
        private GateInterface $gate,
    ) {}

    /**
     * GET /api/v1/forum/moderation/reports: List reports by status.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function reports(ServerRequestInterface $request): Response
    {
        $this->requireModerator($request);

        $params = $request->getQueryParams();
        /** @var mixed $rawStatus */
        $rawStatus = $params['status'] ?? null;
        $statusFilter = is_string($rawStatus) ? $rawStatus : 'pending';
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, (is_int($rawPage) || is_string($rawPage)) && is_numeric($rawPage) ? (int) $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, (is_int($rawPerPage) || is_string($rawPerPage)) && is_numeric($rawPerPage) ? (int) $rawPerPage : 20));

        $status = ReportStatus::tryFrom($statusFilter) ?? ReportStatus::Pending;

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $threadReports = $this->threadReportRepository->findByStatus($status, $page, $perPage, $tenantId);
        $postReports = $this->postReportRepository->findByStatus($status, $page, $perPage, $tenantId);

        $threadData = array_map(static fn(ThreadReport $r) => [
            'id' => $r->id,
            'type' => 'thread',
            'target_id' => $r->threadId,
            'reporter_id' => $r->reporterId,
            'reason' => $r->reason,
            'status' => $r->status->value,
            'created_at' => $r->createdAt->format('c'),
        ], $threadReports->items);

        $postData = array_map(static fn(PostReport $r) => [
            'id' => $r->id,
            'type' => 'post',
            'target_id' => $r->postId,
            'reporter_id' => $r->reporterId,
            'reason' => $r->reason,
            'status' => $r->status->value,
            'created_at' => $r->createdAt->format('c'),
        ], $postReports->items);

        return Response::json([
            'data' => [
                'thread_reports' => $threadData,
                'post_reports' => $postData,
            ],
            'filter' => ['status' => $statusFilter],
        ]);
    }

    /**
     * POST /api/v1/forum/moderation/reports/{id}/review: Review a report.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function reviewReport(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireModerator($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        /** @var mixed $rawAction */
        $rawAction = $body['action'] ?? null;
        $action = is_string($rawAction) ? $rawAction : '';
        /** @var mixed $rawNote */
        $rawNote = $body['note'] ?? null;
        $note = is_string($rawNote) ? $rawNote : '';
        /** @var mixed $rawType */
        $rawType = $body['type'] ?? null;
        $type = is_string($rawType) ? $rawType : 'thread';

        if (!in_array($action, ['action', 'dismiss'], true)) {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['action' => 'Must be "action" or "dismiss"'],
            ], 422);
        }

        $status = $action === 'action' ? ReportStatus::Actioned : ReportStatus::Dismissed;

        try {
            if ($type === 'post') {
                $report = $this->moderationService->reviewPostReport($id, $status, $identity->id(), $note);

                return Response::json([
                    'data' => [
                        'id' => $report->id,
                        'type' => 'post',
                        'status' => $report->status->value,
                    ],
                ]);
            }

            $report = $this->moderationService->reviewThreadReport($id, $status, $identity->id(), $note);

            return Response::json([
                'data' => [
                    'id' => $report->id,
                    'type' => 'thread',
                    'status' => $report->status->value,
                ],
            ]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/forum/moderation/ban/{userId}: Ban a user.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function ban(ServerRequestInterface $request, string $userId): Response
    {
        $identity = $this->requireModerator($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

        if ($reason === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['reason' => 'Ban reason is required'],
            ], 422);
        }

        /** @var mixed $rawExpiresAt */
        $rawExpiresAt = $body['expires_at'] ?? null;
        $expiresAt = is_string($rawExpiresAt) && $rawExpiresAt !== ''
            ? new DateTimeImmutable($rawExpiresAt)
            : null;

        try {
            $profile = $this->moderationService->banUser($userId, $reason, $expiresAt, $identity->id());

            return Response::json([
                'data' => [
                    'user_id' => $profile->userId,
                    'is_banned' => $profile->isBanned,
                    'ban_reason' => $profile->banReason,
                    'banned_at' => $profile->bannedAt?->format('c'),
                    'ban_expires_at' => $profile->banExpiresAt?->format('c'),
                ],
            ]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/forum/moderation/unban/{userId}: Unban a user.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function unban(ServerRequestInterface $request, string $userId): Response
    {
        $identity = $this->requireModerator($request);

        try {
            $profile = $this->moderationService->unbanUser($userId, $identity->id());

            return Response::json([
                'data' => [
                    'user_id' => $profile->userId,
                    'is_banned' => $profile->isBanned,
                ],
            ]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Require an authenticated identity from the request.
     *
     * @throws ForumException If no authenticated identity is present
     */
    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw ForumException::unauthorized('authentication_required');
        }

        return $identity;
    }

    /**
     * Require the authenticated identity to have moderator permissions.
     *
     * @throws ForumException If no authenticated identity is present
     */
    private function requireModerator(ServerRequestInterface $request): IdentityInterface
    {
        $identity = $this->requireIdentity($request);

        if (!$this->gate->allows($identity, 'forum.moderate')) {
            throw ForumException::unauthorized('forum.moderate');
        }

        return $identity;
    }
}
