<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Domain;

use Pulsar\Api\Api;

/**
 * Status of a health incident through its lifecycle.
 */
#[Api(since: '1.0.0')]
enum IncidentStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
}
