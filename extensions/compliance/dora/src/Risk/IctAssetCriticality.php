<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Risk;

use Pulsar\Api\Api;

/**
 * Criticality classification for ICT assets per DORA Article 8.
 */
#[Api(since: '1.0.0')]
enum IctAssetCriticality: string
{
    case Critical = 'critical';
    case Important = 'important';
    case Standard = 'standard';
    case Low = 'low';
}
