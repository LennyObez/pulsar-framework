<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Validator;

use Pulsar\Api\Api;

/**
 * Severity level for accessibility violations.
 */
#[Api(since: '1.0.0')]
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
