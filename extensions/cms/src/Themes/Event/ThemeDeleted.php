<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a theme has been deleted.
 *
 * @psalm-api Event constructed by ThemeManager and dispatched through
 *            the EventDispatcher.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThemeDeleted
{
    public function __construct(
        public string $themeId,
        public string $deletedBy,
        public string $reason,
    ) {}
}
