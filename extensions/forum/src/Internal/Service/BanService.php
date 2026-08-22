<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Domain\BanType;
use Pulsar\Extension\Forum\Domain\ModerationAction;
use Pulsar\Extension\Forum\Event\UserBanned;
use Pulsar\Extension\Forum\Event\UserUnbanned;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\ForumModerationLog;
use Pulsar\Extension\Forum\Report\ForumModerationLogRepositoryInterface;
use Pulsar\Extension\Forum\Report\UserBan;
use Pulsar\Extension\Forum\Report\UserBanRepositoryInterface;
use Pulsar\Extension\Forum\Service\BanServiceInterface;

/**
 * Ban service: manages user bans with full audit trail via moderation logs.
 */
#[Internal(reason: 'Use BanServiceInterface for public API')]
final readonly class BanService implements BanServiceInterface
{
    public function __construct(
        private UserBanRepositoryInterface $bans,
        private ForumProfileRepositoryInterface $profiles,
        private ForumModerationLogRepositoryInterface $moderationLogs,
        private EventDispatcherInterface $events,
    ) {}

    public function ban(
        string $userId,
        string $bannedBy,
        string $reason,
        BanType $type,
        ?DateTimeImmutable $expiresAt = null,
    ): UserBan {
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            throw ForumException::notFound('ForumProfile', $userId);
        }

        if ($profile->isBanned) {
            throw ForumException::banned($userId);
        }

        $ban = UserBan::create(
            userId: $userId,
            bannedBy: $bannedBy,
            reason: $reason,
            type: $type,
            expiresAt: $expiresAt,
        );

        $this->bans->save($ban);

        $profile = $profile->ban($reason, $expiresAt);
        $this->profiles->save($profile);

        $log = ForumModerationLog::create(
            moderatorId: $bannedBy,
            action: ModerationAction::Ban,
            targetType: 'user',
            targetId: $userId,
            reason: $reason,
        );
        $this->moderationLogs->save($log);

        $this->events->dispatch(new UserBanned(
            userId: $userId,
            bannedBy: $bannedBy,
            reason: $reason,
            expiresAt: $expiresAt,
            tenantId: $profile->tenantId,
        ));

        return $ban;
    }

    public function unban(string $userId, string $moderatorId): void
    {
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            throw ForumException::notFound('ForumProfile', $userId);
        }

        $activeBan = $this->bans->findActiveByUser($userId);

        if ($activeBan !== null) {
            $revokedBan = $activeBan->revoke();
            $this->bans->save($revokedBan);
        }

        $profile = $profile->unban();
        $this->profiles->save($profile);

        $log = ForumModerationLog::create(
            moderatorId: $moderatorId,
            action: ModerationAction::Unban,
            targetType: 'user',
            targetId: $userId,
            reason: 'Ban revoked',
        );
        $this->moderationLogs->save($log);

        $this->events->dispatch(new UserUnbanned(
            userId: $userId,
            unbannedBy: $moderatorId,
            tenantId: $profile->tenantId,
        ));
    }

    public function isCurrentlyBanned(string $userId): bool
    {
        $ban = $this->bans->findActiveByUser($userId);

        if ($ban === null) {
            return false;
        }

        return $ban->isActive();
    }

    public function getActiveBan(string $userId): ?UserBan
    {
        $ban = $this->bans->findActiveByUser($userId);

        if ($ban === null) {
            return null;
        }

        return $ban->isActive() ? $ban : null;
    }
}
