<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Pulsar\Api\Api;

/**
 * Severity level for WAF rules, matching OWASP CRS convention.
 * @api
 */
#[Api(since: '1.0.0')]
enum WafSeverity: int
{
    case Critical = 2;
    case Error = 3;
    case Warning = 4;
    case Notice = 5;
}
