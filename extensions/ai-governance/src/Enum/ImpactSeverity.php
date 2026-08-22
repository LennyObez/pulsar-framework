<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Enum;

use Pulsar\Api\Api;

/**
 * Severity levels for impact assessment findings.
 * @api
 */
#[Api(since: '1.0.0')]
enum ImpactSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
