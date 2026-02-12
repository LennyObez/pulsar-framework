<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when content is archived.
 */
#[Api(since: '1.0.0')]
final readonly class ContentArchived
{
    public function __construct(
        public string $contentId,
        public string $archivedBy,
        public ?string $reason,
    ) {}
}
