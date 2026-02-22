<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;

/**
 * Notification sent to thread subscribers when a new reply is posted.
 */
#[Api(since: '1.0.0')]
final readonly class ThreadReplyNotification implements ForumNotificationInterface
{
    /**
     * @param list<string> $recipientUserIds User IDs of thread subscribers
     */
    public function __construct(
        public string $threadId,
        public string $threadTitle,
        public string $postId,
        public string $authorId,
        public string $authorName,
        private array $recipientUserIds,
    ) {}

    public function type(): string
    {
        return 'thread_reply';
    }

    public function recipientIds(): array
    {
        return $this->recipientUserIds;
    }

    public function subject(): string
    {
        return "New reply in: {$this->threadTitle}";
    }

    public function body(): string
    {
        return "{$this->authorName} replied to the thread \"{$this->threadTitle}\".";
    }

    public function metadata(): array
    {
        return [
            'thread_id' => $this->threadId,
            'post_id' => $this->postId,
            'author_id' => $this->authorId,
        ];
    }
}
