<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;

/**
 * HTML link target attribute for navigation menu items.
 *
 * @psalm-api Public enum referenced by MenuItem::linkTarget; consumed by
 *            navigation rendering and admin editor.
 */
#[Api(since: '1.0.0')]
enum LinkTarget: string
{
    case Self = '_self';
    case Blank = '_blank';
}
