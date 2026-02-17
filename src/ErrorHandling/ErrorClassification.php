<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Pulsar\Api\Api;

/**
 * Classification of errors for response handling and retry decisions.
 *
 * Transient errors get 503 + Retry-After (infrastructure issues, timeouts).
 * Permanent errors get 500 (bugs, data corruption).
 * Validation errors get 422 (malformed input).
 * Security errors get 403 (access denied, CSRF failures).
 */
#[Api(since: '1.0.0-rc.11')]
enum ErrorClassification: string
{
    case Transient = 'transient';
    case Permanent = 'permanent';
    case Validation = 'validation';
    case Security = 'security';
}
