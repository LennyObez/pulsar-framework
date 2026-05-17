<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Pulsar\Api\Api;

/**
 * Request component that a WAF rule inspects.
 * @api
 */
#[Api(since: '1.0.0')]
enum WafTarget: string
{
    case Args = 'ARGS';
    case Headers = 'HEADERS';
    case Body = 'BODY';
    case Uri = 'URI';
    case Cookies = 'COOKIES';
    case UserAgent = 'USER_AGENT';
    case Method = 'METHOD';
}
