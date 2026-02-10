<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a theme has been activated.
 */
#[Api(since: '1.0.0')]
final readonly class ThemeActivated
{
    public function __construct(
        public string $themeId,
        public string $activatedBy,
    ) {}
}
