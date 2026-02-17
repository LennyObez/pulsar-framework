<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Attribution models for conversion credit assignment.
 */
#[Api(since: '1.0.0')]
enum AttributionModel: string
{
    case FirstTouch = 'first_touch';
    case LastTouch = 'last_touch';
    case Linear = 'linear';
    case TimeDecay = 'time_decay';
}
