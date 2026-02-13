<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;

/**
 * Notification sent to a post author when their post receives an upvote.
 */
#[Api(since: '1.0.0')]
final readonly class PostUpvotedNotification implements ForumNotificationInterface
{
    public function __construct(
        public string $postId,
        public string $threadId,
        public string $threadTitle,
        public string $postAuthorId,
        public string $voterId,
    ) {}

    public function type(): string
    {
        return 'post_upvoted';
    }

    public function recipientIds(): array
    {
        return [$this->postAuthorId];
    }

    public function subject(): string
    {
        return "Your post was upvoted in: $this->threadTitle";
    }

    public function body(): string
    {
        return "Someone upvoted your post in the thread \"$this->threadTitle\".";
    }

    public function metadata(): array
    {
        return [
            'post_id' => $this->postId,
            'thread_id' => $this->threadId,
            'voter_id' => $this->voterId,
        ];
    }
}
