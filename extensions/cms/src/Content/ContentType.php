<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Built-in content types.
 *
 * Plugins may register custom content types beyond these built-in cases.
 * Use {@see ContentTypeRegistry} to manage and validate custom types.
 */
#[Api(since: '1.0.0')]
enum ContentType: string
{
    case Article = 'article';
    case Page = 'page';
}
