<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a new post/reply is created in a thread.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PostCreated
{
    public function __construct(
        public string $postId,
        public string $threadId,
        public string $authorId,
        public ?string $tenantId = null,
        public string $authorDisplayName = '',
    ) {}
}
