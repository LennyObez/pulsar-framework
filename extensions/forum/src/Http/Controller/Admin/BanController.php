<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use DateTimeImmutable;
use Exception;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Domain\BanType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Report\UserBan;
use Pulsar\Extension\Forum\Report\UserBanRepositoryInterface;
use Pulsar\Extension\Forum\Service\BanServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for managing forum user bans.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class BanController
{
    use RendersAdminView;

    public function __construct(
        private BanServiceInterface $banService,
        private UserBanRepositoryInterface $banRepository,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/bans: List active bans with pagination.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.bans');

        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : 20));

        $result = $this->banRepository->findActive($page, $perPage);

        $data = [
            'data' => array_map(static fn(UserBan $ban) => [
                'id' => $ban->id,
                'user_id' => $ban->userId,
                'banned_by' => $ban->bannedBy,
                'reason' => $ban->reason,
                'type' => $ban->type->value,
                'expires_at' => $ban->expiresAt?->format('c'),
                'created_at' => $ban->createdAt->format('c'),
                'is_active' => $ban->isActive(),
            ], $result->items),
            'pagination' => $result->metaToArray(),
        ];

        return $this->respondWithView($request, 'admin.forum.bans.index', $data);
    }

    /**
     * GET /admin/forum/bans/{id}: Show ban detail.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.bans');

        $ban = $this->banRepository->findById($id);

        if ($ban === null) {
            return Response::json(['error' => 'Ban not found'], 404);
        }

        $userHistory = $this->banRepository->findByUser($ban->userId);

        $data = [
            'ban' => [
                'id' => $ban->id,
                'user_id' => $ban->userId,
                'banned_by' => $ban->bannedBy,
                'reason' => $ban->reason,
                'type' => $ban->type->value,
                'expires_at' => $ban->expiresAt?->format('c'),
                'created_at' => $ban->createdAt->format('c'),
                'revoked_at' => $ban->revokedAt?->format('c'),
                'is_active' => $ban->isActive(),
            ],
            'user_ban_history' => array_map(static fn(UserBan $b) => [
                'id' => $b->id,
                'type' => $b->type->value,
                'reason' => $b->reason,
                'created_at' => $b->createdAt->format('c'),
                'revoked_at' => $b->revokedAt?->format('c'),
                'is_active' => $b->isActive(),
            ], $userHistory),
        ];

        return $this->respondWithView($request, 'admin.forum.bans.show', $data);
    }

    /**
     * POST /admin/forum/bans: Create a new ban.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.bans.create');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawUserId */
        $rawUserId = $body['user_id'] ?? null;
        $userId = is_string($rawUserId) ? $rawUserId : '';
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

        if ($userId === '' || $reason === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => [
                    'user_id' => $userId === '' ? 'User ID is required' : null,
                    'reason' => $reason === '' ? 'Reason is required' : null,
                ],
            ], 422);
        }

        /** @var mixed $rawType */
        $rawType = $body['type'] ?? null;
        $typeValue = is_string($rawType) ? $rawType : 'temporary';
        $type = BanType::tryFrom($typeValue) ?? BanType::Temporary;

        $expiresAt = null;

        /** @var mixed $rawExpiresAt */
        $rawExpiresAt = $body['expires_at'] ?? null;
        if ($type === BanType::Temporary && is_string($rawExpiresAt) && $rawExpiresAt !== '') {
            try {
                $expiresAt = new DateTimeImmutable($rawExpiresAt);
            } catch (Exception) {
                return Response::json([
                    'error' => 'Validation failed',
                    'status' => 422,
                    'details' => ['expires_at' => 'Invalid date format'],
                ], 422);
            }
        }

        try {
            $ban = $this->banService->ban($userId, $identity->id(), $reason, $type, $expiresAt);

            return Response::json([
                'data' => [
                    'id' => $ban->id,
                    'user_id' => $ban->userId,
                    'type' => $ban->type->value,
                    'reason' => $ban->reason,
                    'expires_at' => $ban->expiresAt?->format('c'),
                    'created_at' => $ban->createdAt->format('c'),
                ],
            ], 201);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/forum/bans/{id}/revoke: Revoke an active ban.
     */
    public function revoke(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.bans.revoke');

        $ban = $this->banRepository->findById($id);

        if ($ban === null) {
            return Response::json(['error' => 'Ban not found'], 404);
        }

        if (!$ban->isActive()) {
            return Response::json(['error' => 'Ban is not currently active'], 422);
        }

        try {
            $this->banService->unban($ban->userId, $identity->id());

            return Response::json([
                'data' => [
                    'id' => $id,
                    'status' => 'revoked',
                ],
            ]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }
}
