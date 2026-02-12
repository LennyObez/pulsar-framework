<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ThreadType;

/**
 * Dispatched when a new thread is created.
 */
#[Api(since: '1.0.0')]
final readonly class ThreadCreated
{
    public function __construct(
        public string $threadId,
        public string $categoryId,
        public string $authorId,
        public string $title,
        public ThreadType $type,
        public ?string $tenantId = null,
    ) {}
}
