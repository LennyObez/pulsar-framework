<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Report\ForumModerationLog;
use Pulsar\Extension\Forum\Report\ForumModerationLogRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for viewing the moderation action history log.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class ModerationLogController
{
    use RendersAdminView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ForumModerationLogRepositoryInterface $moderationLogRepository,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/moderation-log: Paginated moderation action log.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.moderation-log');

        $params = $request->getQueryParams();
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_numeric($rawPage) ? (int) $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_numeric($rawPerPage) ? (int) $rawPerPage : 20));

        /** @var mixed $rawModeratorId */
        $rawModeratorId = $params['moderator_id'] ?? null;
        $moderatorFilter = is_string($rawModeratorId) && $rawModeratorId !== ''
            ? $rawModeratorId
            : null;

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($moderatorFilter !== null) {
            $result = $this->moderationLogRepository->findByModerator($moderatorFilter, $page, $perPage);
        } else {
            $result = $this->moderationLogRepository->findRecent($page, $perPage, $tenantId);
        }

        $data = [
            'data' => array_map(static fn(ForumModerationLog $log) => [
                'id' => $log->id,
                'moderator_id' => $log->moderatorId,
                'action' => $log->action->value,
                'target_type' => $log->targetType,
                'target_id' => $log->targetId,
                'reason' => $log->reason,
                'created_at' => $log->createdAt->format('c'),
            ], $result->items),
            'filter' => ['moderator_id' => $moderatorFilter],
            'pagination' => $result->metaToArray(),
        ];

        return $this->respondWithView($request, 'admin.forum.moderation-log.index', $data);
    }
}
