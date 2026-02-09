<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Rounding mode for Money arithmetic.
 */
#[Api(since: '1.0.0')]
enum RoundingMode
{
    case HalfUp;
    case HalfDown;
    case HalfEven;
    case Floor;
    case Ceiling;
}
