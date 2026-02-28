<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;

/**
 * Capabilities a CMS plugin can declare.
 *
 * @psalm-api Public enum referenced by PluginManifest::capabilities; consumed
 *            by manifest validator and admin views.
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
