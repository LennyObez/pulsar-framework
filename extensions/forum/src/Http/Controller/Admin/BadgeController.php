<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;

/**
 * Admin controller for badge management: overview, manual award/revoke.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class BadgeController
{
    use RendersAdminView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private BadgeServiceInterface $badgeService,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/badges: Badge overview with available badges.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.badges');

        $badges = Badge::cases();

        $data = [
            'badges' => array_map(static fn(Badge $b) => [
                'value' => $b->value,
                'name' => $b->name,
            ], $badges),
        ];

        return $this->respondWithView($request, 'admin.forum.badges.index', $data);
    }

    /**
     * GET /admin/forum/badges/user/{userId}: Show badges for a user.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function userBadges(ServerRequestInterface $request, string $userId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.badges');

        $badges = $this->badgeService->getUserBadges($userId);

        $data = [
            'user_id' => $userId,
            'badges' => array_map(static fn(UserBadge $b) => [
                'id' => $b->id,
                'badge' => $b->badge->value,
                'awarded_at' => $b->awardedAt->format('c'),
            ], $badges),
        ];

        return $this->respondWithView($request, 'admin.forum.badges.user', $data);
    }

    /**
     * POST /admin/forum/badges/award: Manually award a badge to a user.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function award(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.badges.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawUserId */
        $rawUserId = $body['user_id'] ?? null;
        $userId = is_string($rawUserId) ? $rawUserId : '';
        /** @var mixed $rawBadge */
        $rawBadge = $body['badge'] ?? null;
        $badgeValue = is_string($rawBadge) ? $rawBadge : '';

        if ($userId === '' || $badgeValue === '') {
            return Response::json(['error' => 'user_id and badge are required'], 422);
        }

        $badge = Badge::tryFrom($badgeValue);

        if ($badge === null) {
            return Response::json(['error' => 'Invalid badge type'], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $userBadge = $this->badgeService->award($userId, $badge, $tenantId);

        if ($userBadge === null) {
            return Response::json([
                'data' => ['user_id' => $userId, 'badge' => $badgeValue, 'status' => 'already_awarded'],
            ]);
        }

        return Response::json([
            'data' => [
                'id' => $userBadge->id,
                'user_id' => $userBadge->userId,
                'badge' => $userBadge->badge->value,
                'awarded_at' => $userBadge->awardedAt->format('c'),
            ],
        ], 201);
    }

    /**
     * POST /admin/forum/badges/revoke: Revoke a badge from a user.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function revoke(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.badges.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawUserId */
        $rawUserId = $body['user_id'] ?? null;
        $userId = is_string($rawUserId) ? $rawUserId : '';
        /** @var mixed $rawBadge */
        $rawBadge = $body['badge'] ?? null;
        $badgeValue = is_string($rawBadge) ? $rawBadge : '';

        if ($userId === '' || $badgeValue === '') {
            return Response::json(['error' => 'user_id and badge are required'], 422);
        }

        $badge = Badge::tryFrom($badgeValue);

        if ($badge === null) {
            return Response::json(['error' => 'Invalid badge type'], 422);
        }

        try {
            $this->badgeService->revoke($userId, $badge);

            return Response::json([
                'data' => ['user_id' => $userId, 'badge' => $badgeValue, 'status' => 'revoked'],
            ]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }
}
