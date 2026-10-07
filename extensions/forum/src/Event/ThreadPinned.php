<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a thread is pinned.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThreadPinned
{
    public function __construct(
        public string $threadId,
        public string $pinnedBy,
        public ?string $tenantId = null,
    ) {}
}
