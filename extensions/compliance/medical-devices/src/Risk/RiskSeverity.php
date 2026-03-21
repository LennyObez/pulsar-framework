<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Risk;

use Pulsar\Api\Api;

/**
 * Severity of harm in risk assessment per ISO 14971.
 * @api
 */
#[Api(since: '1.0.0')]
enum RiskSeverity: string
{
    case Negligible = 'negligible';
    case Minor = 'minor';
    case Serious = 'serious';
    case Critical = 'critical';
    case Catastrophic = 'catastrophic';
}
