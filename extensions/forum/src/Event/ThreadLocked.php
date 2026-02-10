<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a thread is locked.
 */
#[Api(since: '1.0.0')]
final readonly class ThreadLocked
{
    public function __construct(
        public string $threadId,
        public string $lockedBy,
        public ?string $tenantId = null,
    ) {}
}
