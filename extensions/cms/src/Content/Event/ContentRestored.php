<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when archived content is restored to draft.
 *
 * @psalm-api Event class — dispatched by the content service through
 *            the EventDispatcher.
 */
#[Api(since: '1.0.0')]
final readonly class ContentRestored
{
    public function __construct(
        public string $contentId,
        public string $restoredBy,
        public ?string $reason,
    ) {}
}
