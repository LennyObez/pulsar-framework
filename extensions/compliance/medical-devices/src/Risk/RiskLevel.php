<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Risk;

use Pulsar\Api\Api;

/**
 * Risk level assessment result per ISO 14971.
 */
#[Api(since: '1.0.0')]
enum RiskLevel: string
{
    case Acceptable = 'acceptable';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
