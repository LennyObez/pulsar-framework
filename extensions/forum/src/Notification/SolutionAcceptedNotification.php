<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;

/**
 * Notification sent when a user's post is accepted as the solution.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SolutionAcceptedNotification implements ForumNotificationInterface
{
    public function __construct(
        public string $postId,
        public string $threadId,
        public string $threadTitle,
        public string $postAuthorId,
        public string $acceptedBy,
    ) {}

    public function type(): string
    {
        return 'solution_accepted';
    }

    public function recipientIds(): array
    {
        return [$this->postAuthorId];
    }

    public function subject(): string
    {
        return "Your answer was accepted in: $this->threadTitle";
    }

    public function body(): string
    {
        return "Your post was marked as the accepted solution in the thread \"$this->threadTitle\".";
    }

    public function metadata(): array
    {
        return [
            'post_id' => $this->postId,
            'thread_id' => $this->threadId,
            'accepted_by' => $this->acceptedBy,
        ];
    }
}
