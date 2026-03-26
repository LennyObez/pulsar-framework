<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a new comment is posted on content.
 *
 * @psalm-api Public event class dispatched by CommentService and consumed by
 *            CmsNotificationDispatcher.
 */
#[Api(since: '1.0.0')]
final readonly class CommentReceived
{
    public function __construct(
        public string $contentId,
        public string $contentTitle,
        public string $commentId,
        public string $authorName,
        public string $commentBody,
    ) {}
}
