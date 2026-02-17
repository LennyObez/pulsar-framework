<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Risk;

use Pulsar\Api\Api;

/**
 * Probability of occurrence in risk assessment per ISO 14971.
 */
#[Api(since: '1.0.0')]
enum RiskProbability: string
{
    case Incredible = 'incredible';
    case Improbable = 'improbable';
    case Remote = 'remote';
    case Occasional = 'occasional';
    case Probable = 'probable';
    case Frequent = 'frequent';
}
