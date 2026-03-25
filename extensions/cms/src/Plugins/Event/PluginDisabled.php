<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a plugin has been disabled.
 *
 * @psalm-api Event constructed by CmsPluginManager and dispatched through
 *            the EventDispatcher.
 */
#[Api(since: '1.0.0')]
final readonly class PluginDisabled
{
    public function __construct(
        public string $pluginId,
        public string $disabledBy,
    ) {}
}
