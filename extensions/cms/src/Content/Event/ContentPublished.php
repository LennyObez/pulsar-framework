<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when content is published.
 */
#[Api(since: '1.0.0')]
final readonly class ContentPublished
{
    public function __construct(
        public string $contentId,
        public string $publishedBy,
        public ?string $reason,
    ) {}
}
