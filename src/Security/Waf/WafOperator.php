<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Pulsar\Api\Api;

/**
 * Matching operators for WAF rules.
 * @api
 */
#[Api(since: '1.0.0')]
enum WafOperator: string
{
    case Contains = 'contains';
    case Regex = 'regex';
    case BeginsWith = 'beginsWith';
    case EndsWith = 'endsWith';
    case DetectSqli = 'detectSQLi';
    case DetectXss = 'detectXSS';
    case Equals = 'equals';
}
