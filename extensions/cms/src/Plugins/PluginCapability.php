<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;

/**
 * Capabilities a CMS plugin can declare.
 */
#[Api(since: '1.0.0')]
enum PluginCapability: string
{
    case ContentTypes = 'content_types';
    case AdminPages = 'admin_pages';
    case Hooks = 'hooks';
    case Shortcodes = 'shortcodes';
    case BlockTypes = 'block_types';
}
