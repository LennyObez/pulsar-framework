<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a new comment is submitted.
 */
#[Api(since: '1.0.0')]
final readonly class CommentSubmitted
{
    public function __construct(
        public string $commentId,
        public string $contentId,
        public ?string $authorId,
    ) {}
}
