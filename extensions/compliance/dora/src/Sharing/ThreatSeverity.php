<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Sharing;

use Pulsar\Api\Api;

/**
 * Severity classification for cyber threat indicators.
 * @api
 */
#[Api(since: '1.0.0')]
enum ThreatSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Informational = 'informational';
}
