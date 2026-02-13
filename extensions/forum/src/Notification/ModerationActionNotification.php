<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;

/**
 * Notification sent when a moderation action is taken on a user's content.
 */
#[Api(since: '1.0.0')]
final readonly class ModerationActionNotification implements ForumNotificationInterface
{
    /**
     * @param string $targetType 'thread' or 'post'
     * @param string $action Description of the moderation action taken
     */
    public function __construct(
        public string $targetType,
        public string $targetId,
        public string $targetAuthorId,
        public string $moderatorId,
        public string $action,
        public string $reason,
    ) {}

    public function type(): string
    {
        return 'moderation_action';
    }

    public function recipientIds(): array
    {
        return [$this->targetAuthorId];
    }

    public function subject(): string
    {
        return "Moderation action on your $this->targetType";
    }

    public function body(): string
    {
        return "A moderator has taken action on your $this->targetType: $this->action. Reason: $this->reason";
    }

    public function metadata(): array
    {
        return [
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'moderator_id' => $this->moderatorId,
            'action' => $this->action,
            'reason' => $this->reason,
        ];
    }
}
