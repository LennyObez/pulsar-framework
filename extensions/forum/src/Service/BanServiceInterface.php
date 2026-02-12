<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\BanType;
use Pulsar\Extension\Forum\Report\UserBan;

/**
 * Service for managing forum user bans with full audit trail.
 */
#[Api(since: '1.0.0')]
interface BanServiceInterface
{
    /**
     * Ban a user from the forum.
     *
     * Creates a UserBan record, updates the user's ForumProfile ban state,
     * logs the moderation action, and dispatches a UserBanned event.
     */
    public function ban(
        string $userId,
        string $bannedBy,
        string $reason,
        BanType $type,
        ?DateTimeImmutable $expiresAt = null,
    ): UserBan;

    /**
     * Unban a user from the forum.
     *
     * Revokes the active UserBan record, updates the user's ForumProfile,
     * logs the moderation action, and dispatches a UserUnbanned event.
     */
    public function unban(string $userId, string $moderatorId): void;

    /**
     * Check if a user is currently banned.
     */
    public function isCurrentlyBanned(string $userId): bool;

    /**
     * Get the active ban for a user, if any.
     */
    public function getActiveBan(string $userId): ?UserBan;
}
