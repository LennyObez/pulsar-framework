<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when content is submitted for editorial review.
 *
 * @psalm-api Public event class dispatched by EditorialWorkflowService and
 *            consumed by CmsNotificationDispatcher.
 */
#[Api(since: '1.0.0')]
final readonly class ReviewRequested
{
    public function __construct(
        public string $contentId,
        public string $requesterId,
        public ?string $reviewerId,
        public string $contentTitle,
        public ?string $message,
    ) {}
}
