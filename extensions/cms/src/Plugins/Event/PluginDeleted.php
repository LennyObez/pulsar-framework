<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a plugin has been deleted.
 */
#[Api(since: '1.0.0')]
final readonly class PluginDeleted
{
    public function __construct(
        public string $pluginId,
        public string $deletedBy,
        public string $reason,
    ) {}
}
