<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use Pulsar\Api\Api;

/**
 * Supported OpenAPI security scheme types.
 */
#[Api(since: '1.0.0')]
enum SecuritySchemeType: string
{
    case Http = 'http';
    case ApiKey = 'apiKey';
    case OAuth2 = 'oauth2';
    case OpenIdConnect = 'openIdConnect';
}
