<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a content translation is updated.
 */
#[Api(since: '1.0.0')]
final readonly class ContentUpdated
{
    /**
     * @param list<string> $changedFields Names of fields that changed
     */
    public function __construct(
        public string $contentId,
        public string $locale,
        public array $changedFields,
    ) {}
}
