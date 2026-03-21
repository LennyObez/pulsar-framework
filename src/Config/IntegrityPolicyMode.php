<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Integrity verification policy modes.
 * @api
 */
#[Api(since: '1.0.0')]
enum IntegrityPolicyMode: string
{
    case Warn = 'warn';
    case Strict = 'strict';
}
