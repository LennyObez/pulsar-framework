<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Domain;

use Pulsar\Api\Api;

/**
 * Severity level for a health incident.
 */
#[Api(since: '1.0.0')]
enum IncidentSeverity: string
{
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';
}
