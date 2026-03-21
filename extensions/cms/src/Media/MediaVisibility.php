<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;

/**
 * Visibility level for media assets.
 * @api
 */
#[Api(since: '1.0.0')]
enum MediaVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
