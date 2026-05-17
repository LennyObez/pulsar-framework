<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a thread is unpinned from the top of its category.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThreadUnpinned
{
    public function __construct(
        public string $threadId,
        public string $unpinnedBy,
        public ?string $tenantId = null,
    ) {}
}
