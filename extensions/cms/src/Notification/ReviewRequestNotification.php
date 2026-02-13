<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Notification;

use Pulsar\Api\Api;

/**
 * Notification dispatched when content is submitted for editorial review.
 */
#[Api(since: '1.0.0')]
final readonly class ReviewRequestNotification implements CmsNotificationInterface
{
    /**
     * @param list<string> $reviewerIds User IDs of assigned reviewers
     */
    public function __construct(
        public string $contentId,
        public string $contentTitle,
        public string $requesterId,
        public array $reviewerIds,
        public ?string $message,
    ) {}

    public function type(): string
    {
        return 'review_requested';
    }

    public function recipientIds(): array
    {
        return $this->reviewerIds;
    }

    public function subject(): string
    {
        return "Review requested: $this->contentTitle";
    }

    public function body(): string
    {
        $body = "A review has been requested for \"$this->contentTitle\".";

        if ($this->message !== null && $this->message !== '') {
            $body .= " Message: $this->message";
        }

        return $body;
    }

    public function metadata(): array
    {
        return [
            'content_id' => $this->contentId,
            'requester_id' => $this->requesterId,
            'reviewer_ids' => $this->reviewerIds,
            'message' => $this->message,
        ];
    }
}
