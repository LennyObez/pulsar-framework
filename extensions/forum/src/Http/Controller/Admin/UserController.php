<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Admin controller for user management: ban, unban, promote, view profile.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class UserController
{
    use RendersAdminView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ForumProfileRepositoryInterface $profileRepository,
        private ModerationServiceInterface $moderationService,
        private ReputationServiceInterface $reputationService,
        private BadgeServiceInterface $badgeService,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/users/{userId}: View a user's forum profile.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function show(ServerRequestInterface $request, string $userId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.users');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $profile = $this->profileRepository->findByUser($userId, $tenantId);

        if ($profile === null) {
            return Response::json(['error' => 'Forum profile not found'], 404);
        }

        $badges = $this->badgeService->getUserBadges($userId);
        $level = $this->reputationService->getLevel($userId);

        $data = [
            'profile' => self::serializeProfile($profile),
            'badges' => array_map(static fn(UserBadge $b) => [
                'badge' => $b->badge->value,
                'awarded_at' => $b->awardedAt->format('c'),
            ], $badges),
            'reputation_level' => $level->value,
        ];

        return $this->respondWithView($request, 'admin.forum.users.show', $data);
    }

    /**
     * POST /admin/forum/users/{userId}/ban: Ban a user.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function ban(ServerRequestInterface $request, string $userId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.users.ban');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

        if ($reason === '') {
            return Response::json(['error' => 'Ban reason is required'], 422);
        }

        /** @var mixed $rawExpiresAt */
        $rawExpiresAt = $body['expires_at'] ?? null;
        $expiresAt = is_string($rawExpiresAt) && $rawExpiresAt !== ''
            ? new DateTimeImmutable($rawExpiresAt)
            : null;

        try {
            $profile = $this->moderationService->banUser($userId, $reason, $expiresAt);

            return Response::json(['data' => self::serializeProfile($profile)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/forum/users/{userId}/unban: Unban a user.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function unban(ServerRequestInterface $request, string $userId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.users.ban');

        try {
            $profile = $this->moderationService->unbanUser($userId);

            return Response::json(['data' => self::serializeProfile($profile)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/forum/users/{userId}/promote: Add reputation points.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function promote(ServerRequestInterface $request, string $userId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.users.promote');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawPoints */
        $rawPoints = $body['points'] ?? null;
        $points = (is_int($rawPoints) || is_string($rawPoints)) && is_numeric($rawPoints) ? (int) $rawPoints : 0;
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : 'admin_promotion';

        if ($points === 0) {
            return Response::json(['error' => 'Points must be non-zero'], 422);
        }

        try {
            $profile = $this->reputationService->addReputation($userId, $points, $reason);

            return Response::json(['data' => self::serializeProfile($profile)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeProfile(ForumProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->userId,
            'reputation_score' => $profile->reputationScore,
            'reputation_level' => $profile->reputationLevel()->value,
            'post_count' => $profile->postCount,
            'thread_count' => $profile->threadCount,
            'is_banned' => $profile->isBanned,
            'ban_reason' => $profile->banReason,
            'banned_at' => $profile->bannedAt?->format('c'),
            'ban_expires_at' => $profile->banExpiresAt?->format('c'),
            'created_at' => $profile->createdAt->format('c'),
            'updated_at' => $profile->updatedAt->format('c'),
        ];
    }
}
