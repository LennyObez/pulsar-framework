<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a post is soft-deleted.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PostDeleted
{
    public function __construct(
        public string $postId,
        public string $threadId,
        public string $deletedBy,
        public ?string $tenantId = null,
    ) {}
}
