<?php

declare(strict_types=1);

namespace Pulsar\Api\Version;

use Pulsar\Api\Api;

/**
 * Strategy for resolving the API version from a request.
 * @api
 */
#[Api(since: '1.0.0')]
enum VersionStrategy: string
{
    case UrlPrefix = 'url';
    case Header = 'header';
    case QueryParameter = 'query';
}
