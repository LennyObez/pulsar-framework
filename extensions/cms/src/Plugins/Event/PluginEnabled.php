<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a plugin has been enabled.
 *
 * @psalm-api Event constructed by CmsPluginManager and dispatched through
 *            the EventDispatcher.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PluginEnabled
{
    public function __construct(
        public string $pluginId,
        public string $enabledBy,
    ) {}
}
