<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a thread is unlocked by a moderator.
 */
#[Api(since: '1.0.0')]
final readonly class ThreadUnlocked
{
    public function __construct(
        public string $threadId,
        public string $unlockedBy,
        public ?string $tenantId = null,
    ) {}
}
