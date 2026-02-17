<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Pulsar\Api\Api;

/**
 * Action to take when a WAF rule matches.
 */
#[Api(since: '1.0.0')]
enum WafAction: string
{
    case Block = 'block';
    case Log = 'log';
    case Alert = 'alert';
    case Challenge = 'challenge';
}
