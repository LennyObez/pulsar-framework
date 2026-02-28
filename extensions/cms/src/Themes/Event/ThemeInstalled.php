<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a theme has been installed.
 *
 * @psalm-api Event constructed by ThemeManager and dispatched through
 *            the EventDispatcher.
 */
#[Api(since: '1.0.0')]
final readonly class ThemeInstalled
{
    public function __construct(
        public string $themeId,
        public string $name,
        public string $version,
        public string $installedBy,
    ) {}
}
