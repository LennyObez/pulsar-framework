<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Integrity verification policy modes.
 */
#[Api]
enum IntegrityPolicyMode: string
{
    case Warn = 'warn';
    case Strict = 'strict';
}
