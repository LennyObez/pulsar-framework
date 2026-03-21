<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Report;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ModerationAction;
use Pulsar\Extension\Forum\Support\UuidGenerator;

/**
 * Immutable record of a moderation action taken by a moderator.
 *
 * Tracks what action was taken, against which target (post, thread, or user),
 * by whom, and when. Used for audit trails and moderator accountability.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForumModerationLog
{
    /**
     * @param string $id UUIDv7
     * @param string $moderatorId UUIDv7 FK user who performed the action
     * @param ModerationAction $action The moderation action taken
     * @param string $targetType Target entity type: 'post', 'thread', or 'user'
     * @param string $targetId UUIDv7 FK target entity
     * @param string $reason Moderator-provided justification
     * @param DateTimeImmutable $createdAt When the action was taken
     */
    public function __construct(
        public string $id,
        public string $moderatorId,
        public ModerationAction $action,
        public string $targetType,
        public string $targetId,
        public string $reason,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a new moderation log entry.
     */
    public static function create(
        string $moderatorId,
        ModerationAction $action,
        string $targetType,
        string $targetId,
        string $reason,
    ): self {
        return new self(
            id: UuidGenerator::v7(),
            moderatorId: $moderatorId,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            reason: $reason,
            createdAt: new DateTimeImmutable(),
        );
    }
}
