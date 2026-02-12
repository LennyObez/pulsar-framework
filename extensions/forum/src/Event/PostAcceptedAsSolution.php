<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a post is marked as the accepted solution for a thread.
 */
#[Api(since: '1.0.0')]
final readonly class PostAcceptedAsSolution
{
    public function __construct(
        public string $postId,
        public string $threadId,
        public string $postAuthorId,
        public string $acceptedBy,
        public ?string $tenantId = null,
    ) {}
}
