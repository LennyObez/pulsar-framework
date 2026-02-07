<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a content translation's slug changes.
 */
#[Api(since: '1.0.0')]
final readonly class SlugChanged
{
    public function __construct(
        public string $contentId,
        public string $locale,
        public string $oldSlug,
        public string $newSlug,
    ) {}
}
