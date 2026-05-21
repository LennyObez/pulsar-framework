<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function in_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for the moderation queue; report review and actions.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class ModerationController
{
    use RendersAdminView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ThreadReportRepositoryInterface $threadReportRepository,
        private PostReportRepositoryInterface $postReportRepository,
        private ModerationServiceInterface $moderationService,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/moderation: Moderation queue.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.moderate');

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

        $data = [
            'thread_reports' => array_map(static fn(ThreadReport $r) => [
                'id' => $r->id,
                'thread_id' => $r->threadId,
                'reporter_id' => $r->reporterId,
                'reason' => $r->reason,
                'status' => $r->status->value,
                'created_at' => $r->createdAt->format('c'),
            ], $threadReports->items),
            'post_reports' => array_map(static fn(PostReport $r) => [
                'id' => $r->id,
                'post_id' => $r->postId,
                'reporter_id' => $r->reporterId,
                'reason' => $r->reason,
                'status' => $r->status->value,
                'created_at' => $r->createdAt->format('c'),
            ], $postReports->items),
            'filter' => ['status' => $statusFilter],
            'thread_pagination' => $threadReports->metaToArray(),
            'post_pagination' => $postReports->metaToArray(),
        ];

        return $this->respondWithView($request, 'admin.forum.moderation.index', $data);
    }

    /**
     * POST /admin/forum/moderation/thread-reports/{id}: Review a thread report.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function reviewThreadReport(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.moderate');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawAction */
        $rawAction = $body['action'] ?? null;
        $action = is_string($rawAction) ? $rawAction : '';
        /** @var mixed $rawNote */
        $rawNote = $body['note'] ?? null;
        $note = is_string($rawNote) ? $rawNote : '';

        if (!in_array($action, ['action', 'dismiss'], true)) {
            return Response::json(['error' => 'Invalid action. Must be: action or dismiss'], 400);
        }

        $status = $action === 'action' ? ReportStatus::Actioned : ReportStatus::Dismissed;

        try {
            $report = $this->moderationService->reviewThreadReport($id, $status, $identity->id(), $note);

            return Response::json([
                'data' => [
                    'id' => $report->id,
                    'status' => $report->status->value,
                    'action' => $action,
                ],
            ]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/forum/moderation/post-reports/{id}: Review a post report.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function reviewPostReport(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.moderate');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawAction */
        $rawAction = $body['action'] ?? null;
        $action = is_string($rawAction) ? $rawAction : '';
        /** @var mixed $rawNote */
        $rawNote = $body['note'] ?? null;
        $note = is_string($rawNote) ? $rawNote : '';

        if (!in_array($action, ['action', 'dismiss'], true)) {
            return Response::json(['error' => 'Invalid action. Must be: action or dismiss'], 400);
        }

        $status = $action === 'action' ? ReportStatus::Actioned : ReportStatus::Dismissed;

        try {
            $report = $this->moderationService->reviewPostReport($id, $status, $identity->id(), $note);

            return Response::json([
                'data' => [
                    'id' => $report->id,
                    'status' => $report->status->value,
                    'action' => $action,
                ],
            ]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }
}
