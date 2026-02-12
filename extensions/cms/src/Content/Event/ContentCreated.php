<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a new content item is created.
 */
#[Api(since: '1.0.0')]
final readonly class ContentCreated
{
    public function __construct(
        public string $contentId,
        public string $contentType,
        public string $authorId,
    ) {}
}
