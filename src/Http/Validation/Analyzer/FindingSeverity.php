<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Analyzer;

use Pulsar\Api\Api;

/**
 * Severity levels for policy analyzer findings.
 */
#[Api(since: '1.0.0')]
enum FindingSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
