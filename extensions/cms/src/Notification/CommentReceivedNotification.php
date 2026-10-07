<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Notification;

use Pulsar\Api\Api;

/**
 * Notification dispatched when a new comment is posted on content.
 *
 * @psalm-api Constructed by CmsNotificationDispatcher and dispatched through
 *            the framework notification manager.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CommentReceivedNotification implements CmsNotificationInterface
{
    /**
     * @param list<string> $recipientUserIds User IDs to notify (e.g. content author, moderators)
     */
    public function __construct(
        public string $contentId,
        public string $contentTitle,
        public string $commentId,
        public string $authorName,
        public string $commentBody,
        private array $recipientUserIds = [],
    ) {}

    public function type(): string
    {
        return 'comment_received';
    }

    public function recipientIds(): array
    {
        return $this->recipientUserIds;
    }

    public function subject(): string
    {
        return "New comment on: $this->contentTitle";
    }

    public function body(): string
    {
        return "$this->authorName commented on \"$this->contentTitle\": $this->commentBody";
    }

    public function metadata(): array
    {
        return [
            'content_id' => $this->contentId,
            'comment_id' => $this->commentId,
            'author_name' => $this->authorName,
        ];
    }
}
