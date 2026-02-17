<?php

declare(strict_types=1);

namespace Pulsar\Security\Assertion;

use Pulsar\Api\Api;

/**
 * Severity levels for security assertion violations.
 */
#[Api(since: '1.0.0')]
enum SecurityViolationSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
