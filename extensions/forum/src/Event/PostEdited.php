<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a post body is edited.
 */
#[Api(since: '1.0.0')]
final readonly class PostEdited
{
    public function __construct(
        public string $postId,
        public string $threadId,
        public string $editedBy,
        public ?string $tenantId = null,
    ) {}
}
