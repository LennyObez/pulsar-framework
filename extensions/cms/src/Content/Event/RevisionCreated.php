<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a new content revision is created.
 *
 * @psalm-api Event class — dispatched by the revision service through
 *            the EventDispatcher.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RevisionCreated
{
    public function __construct(
        public string $revisionId,
        public string $contentId,
        public string $locale,
    ) {}
}
