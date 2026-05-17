<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;

/**
 * Notification sent when a user is @mentioned in a post.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MentionNotification implements ForumNotificationInterface
{
    public function __construct(
        public string $postId,
        public string $threadId,
        public string $threadTitle,
        public string $mentionedUserId,
        public string $authorId,
        public string $authorName,
    ) {}

    public function type(): string
    {
        return 'mention';
    }

    public function recipientIds(): array
    {
        return [$this->mentionedUserId];
    }

    public function subject(): string
    {
        return "$this->authorName mentioned you in: $this->threadTitle";
    }

    public function body(): string
    {
        return "$this->authorName mentioned you in a post in the thread \"$this->threadTitle\".";
    }

    public function metadata(): array
    {
        return [
            'post_id' => $this->postId,
            'thread_id' => $this->threadId,
            'author_id' => $this->authorId,
        ];
    }
}
