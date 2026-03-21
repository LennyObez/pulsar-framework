<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\ThirdParty;

use Pulsar\Api\Api;

/**
 * Risk level assessment for ICT third-party providers.
 * @api
 */
#[Api(since: '1.0.0')]
enum ThirdPartyRiskLevel: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
