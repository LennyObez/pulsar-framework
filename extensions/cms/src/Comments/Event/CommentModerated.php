<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a comment is moderated (approved, rejected, or marked as spam).
 */
#[Api(since: '1.0.0')]
final readonly class CommentModerated
{
    public function __construct(
        public string $commentId,
        public string $contentId,
        public string $moderatorId,
        public string $fromStatus,
        public string $toStatus,
        public string $reason,
    ) {}
}
